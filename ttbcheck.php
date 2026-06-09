<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Alcohol Label Scanner</title>

<style>

* {
    box-sizing: border-box;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f5f7fa;
    margin: 0;
    padding: 20px;
}

.container {
    max-width: 1600px;
    margin: auto;
}

h1 {
    color: #1e3a5f;
}

.panel {
    background: white;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 4px 10px rgba(0,0,0,.08);
}

button {
    background: #2563eb;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 15px;
}

button:hover {
    background: #1d4ed8;
}

#progress {
    margin-top: 15px;
    font-weight: bold;
}

#summary {
    margin-top: 10px;
    font-weight: bold;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}

th {
    background: #1e3a5f;
    color: white;
}

th,
td {
    border: 1px solid #ddd;
    padding: 10px;
    vertical-align: top;
    text-align: left;
}

.true {
    color: green;
    font-weight: bold;
}

.false {
    color: red;
    font-weight: bold;
}

.confidence-high {
    color: green;
    font-weight: bold;
}

.confidence-medium {
    color: darkorange;
    font-weight: bold;
}

.confidence-low {
    color: red;
    font-weight: bold;
}

.ocr-text {
    max-width: 500px;
    max-height: 300px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 12px;
    line-height: 1.4;
}

.processing {
    color: #2563eb;
    font-weight: bold;
}

@media(max-width:768px) {

    body {
        padding: 10px;
    }

    th,
    td {
        font-size: 12px;
        padding: 6px;
    }

    .ocr-text {
        max-width: 250px;
    }
}

</style>
</head>
<body>

<div class="container">

    <h1>Alcohol Label Scanner</h1>

    <div class="panel">

        <input
            type="file"
            id="imageFile"
            multiple
            accept="image/*">

        <br><br>

        <button onclick="checkImages()">
            Scan Images
        </button>

        <div id="progress"></div>
        <div id="summary"></div>

    </div>

    <div class="table-container">

        <table>

            <thead>
                <tr>
                    <th>File</th>
                    <th>Warning</th>
                    <th>Warning %</th>
                    <th>Overall %</th>
                    <th>Company</th>
                    <th>Company %</th>
                    <th>Class / Type</th>
                    <th>Class %</th>
                    <th>Alcohol Content</th>
                    <th>Alcohol %</th>
                    <!--th>Net Contents</th>
                    <th>Net %</th-->
                    <th>OCR Text</th>
                </tr>
            </thead>

            <tbody id="resultsTable"></tbody>

        </table>

    </div>

</div>

<script src="https://unpkg.com/tesseract.js@5/dist/tesseract.min.js"></script>

<script>

function escapeHtml(text) {

    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function confidenceClass(score) {

    if (score >= 90) {
        return 'confidence-high';
    }

    if (score >= 70) {
        return 'confidence-medium';
    }

    return 'confidence-low';
}

function confidenceHtml(score) {

    return `
        <span class="${confidenceClass(score)}">
            ${score}%
        </span>
    `;
}

function averageConfidence(words) {

    if (!words.length) {
        return 0;
    }

    let total = 0;

    words.forEach(w => {
        total += (w.confidence || 0);
    });

    return Math.round(total / words.length);
}

function confidenceForPhrase(words, phrase) {

    if (!phrase ||
        phrase === 'Unknown' ||
        phrase === 'Not Found') {
        return 0;
    }

    const terms =
        phrase
        .toUpperCase()
        .replace(/[^\w\s]/g,' ')
        .split(/\s+/)
        .filter(Boolean);

    const matches =
        words.filter(word => {

            const txt =
                (word.text || '')
                .toUpperCase();

            return terms.some(term =>
                txt.includes(term) ||
                term.includes(txt)
            );
        });

    return averageConfidence(matches);
}

function extractLabelInfo(text) {

    const lines =
        text
        .split('\n')
        .map(x => x.trim())
        .filter(Boolean);

    const result = {

        governmentWarning: false,

        companyName: 'Unknown',

        classType: 'Not Found',

        alcoholContent: 'Not Found',

        netContents: 'Not Found'
    };

    result.governmentWarning =
        text.includes(
            'GOVERNMENT WARNING:'
        );

    const alcoholMatch =
        text.match(
          //  /\d+(?:\.\d+)?\s*%\s*ALC\.?\s*\/\s*VOL\.?(?:\s*\([^)]+\))?/i //too restrictive
		/ALC.*VOL|VOL.*ALC/i
        );

    if (alcoholMatch) {

        result.alcoholContent =
            alcoholMatch[0].trim();
    }

    const netMatch =
        text.match(
            /\b(?:50|100|200|375|500|700|750|1000|1750)\s*(?:ML|L|PT)\b/i
        );

    if (netMatch) {

        result.netContents =
            netMatch[0];
    }

    const classPatterns = [

        /KENTUCKY STRAIGHT BOURBON WHISKEY/i,
        /STRAIGHT BOURBON WHISKEY/i,
        /BOURBON WHISKEY/i,
        /STRAIGHT RYE WHISKEY/i,
        /RYE WHISKEY/i,
        /TENNESSEE WHISKEY/i,
        /SCOTCH WHISKY/i,
        /WHISKEY/i,
        /WHISKY/i,
        /VODKA/i,
        /GIN/i,
	/ ALE/i,
        /RUM/i,
        /TEQUILA/i,
        /MEZCAL/i,
        /COGNAC/i,
        /BRANDY/i
    ];

    for (const pattern of classPatterns) {

        const match =
            text.match(pattern);

        if (match) {

            result.classType =
                match[0];

            break;
        }
    }

    const companyPatterns = [

        /PRODUCED AND BOTTLED BY\s+([^\n]+)/i,
        /BOTTLED BY\s+([^\n]+)/i,
        /DISTILLED BY\s+([^\n]+)/i,
        /PRODUCED BY\s+([^\n]+)/i,
        /IMPORTED BY\s+([^\n]+)/i,
        /MANUFACTURED BY\s+([^\n]+)/i,
	/([^\n]*BREWING[^\n]*)/i
    ];

    for (const pattern of companyPatterns) {

        const match =
            text.match(pattern);

        if (match) {

            result.companyName =
                match[1]
                .trim()
                .replace(/[.,;]+$/, '');

            return result;
        }
    }

    const blacklist = [

        'GOVERNMENT WARNING',
        'SURGEON GENERAL',
        'WHISKEY',
        'WHISKY',
        'BOURBON',
        'GIN',
        'VODKA',
        'RUM',
        'TEQUILA',
        'MEZCAL',
        'COGNAC',
        'BRANDY',
        'ALC',
        'VOL',
        'PROOF',
        'ML',
        'INGREDIENTS'
    ];

    const candidates =
        lines.slice(0,20);

    for (const line of candidates) {

        const upper =
            line.toUpperCase();

        const blocked =
            blacklist.some(x =>
                upper.includes(x));

        if (blocked) {
            continue;
        }

        if (
            line.length >= 4 &&
            line.length <= 40 &&
            !/\d/.test(line)
        ) {

            result.companyName = line;
            break;
        }
    }

    return result;
}

async function checkImages() {

    const files =
        document.getElementById('imageFile').files;

    if (!files.length) {

        alert(
            'Select one or more images.'
        );

        return;
    }

    const tbody =
        document.getElementById(
            'resultsTable'
        );

    const progress =
        document.getElementById(
            'progress'
        );

    const summary =
        document.getElementById(
            'summary'
        );

    tbody.innerHTML = '';

    let passCount = 0;
    let failCount = 0;

    progress.innerHTML =
        'Loading OCR Engine...';

    const worker =
        await Tesseract.createWorker('eng');

    for (let i = 0; i < files.length; i++) {

        const file = files[i];

        progress.innerHTML =
            `Processing ${i + 1} of ${files.length}`;

        const row =
            document.createElement('tr');

        row.innerHTML = `
            <td>${file.name}</td>
            <td class="processing">Processing...</td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <!--td></td>
            <td></td-->
            <td></td>
        `;

        tbody.appendChild(row);

        try {

            const result =
                await worker.recognize(file);

            const originalText =
                result.data.text;

            const upperText =
                originalText.toUpperCase();

            const words =
                result.data.words || [];

            const info =
                extractLabelInfo(
                    upperText
                );

            const warningConfidence =
                info.governmentWarning
                    ? confidenceForPhrase(
                        words,
                        'GOVERNMENT WARNING'
                    )
                    : 0;

            const companyConfidence =
                confidenceForPhrase(
                    words,
                    info.companyName
                );

            const classConfidence =
                confidenceForPhrase(
                    words,
                    info.classType
                );

            const alcoholConfidence =
                confidenceForPhrase(
                    words,
                    info.alcoholContent
                );

            const netConfidence =
                confidenceForPhrase(
                    words,
                    info.netContents
                );

            const confidenceValues = [

                warningConfidence,
                companyConfidence,
                classConfidence,
                alcoholConfidence,
                netConfidence

            ].filter(x => x > 0);

            const overallConfidence =
                confidenceValues.length
                    ? Math.round(
                        confidenceValues.reduce(
                            (a,b)=>a+b,
                            0
                        ) /
                        confidenceValues.length
                    )
                    : 0;

            if (
                info.governmentWarning
            ) {

                passCount++;

                row.cells[1].innerHTML =
                    '<span class="true">TRUE</span>';

            } else {

                failCount++;

                row.cells[1].innerHTML =
                    '<span class="false">FALSE</span>';
            }

            row.cells[2].innerHTML =
                confidenceHtml(
                    warningConfidence
                );

            row.cells[3].innerHTML =
                confidenceHtml(
                    overallConfidence
                );

            row.cells[4].textContent =
                info.companyName;

            row.cells[5].innerHTML =
                confidenceHtml(
                    companyConfidence
                );

            row.cells[6].textContent =
                info.classType;

            row.cells[7].innerHTML =
                confidenceHtml(
                    classConfidence
                );

            row.cells[8].textContent =
                info.alcoholContent;

            row.cells[9].innerHTML =
                confidenceHtml(
                    alcoholConfidence
                );

           // row.cells[10].textContent =
           //     info.netContents;

           // row.cells[11].innerHTML =
           //     confidenceHtml(
           //         netConfidence
           //     );

            row.cells[10].innerHTML =
                `<div class="ocr-text">${escapeHtml(originalText)}</div>`;

        }
        catch(ex) {

            console.error(ex);

            failCount++;

            row.cells[1].innerHTML =
                '<span class="false">ERROR</span>';
        }
    }

    await worker.terminate();

    progress.innerHTML =
        'Completed';

    summary.innerHTML =
        `Passed: ${passCount} | Failed: ${failCount}`;
}

</script>

</body>
</html>
