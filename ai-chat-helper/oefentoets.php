<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// Laad API key uit .env
$env = parse_ini_file('.env');
$apiKey = $env['OPENAI_API_KEY'] ?? null;
$quiz = [];
$feedback = null;
$score = 0;
$error = null;
set_time_limit(300); // 300 seconden = 5 minuten

// Voorbeeld onderwerpen voor suggesties (meer concreet voor afbeeldingen)
$voorbeeldOnderwerpen = [
    "Anatomie van het menselijk hart", 
    "Zonnestelsel en planeten",
    "Geschiedenis van het oude Egypte",
    "Diersoorten in de Amazone",
    "Wereldwonderen van de moderne tijd",
    "Fotosynthese in planten",
    "Computeronderdelen en hun functies"
];

// Als onderwerp is ingestuurd (quiz genereren)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['onderwerp'])) {
    $onderwerp = htmlspecialchars($_POST['onderwerp']);
    $moeilijkheid = htmlspecialchars($_POST['moeilijkheid'] ?? 'gemiddeld');
    $genereerAfbeeldingen = isset($_POST['genereer_afbeeldingen']);
    
    // Vertaal moeilijkheid naar instructies
    $moeilijkheidText = [
        'makkelijk' => 'basisniveau, geschikt voor beginners',
        'gemiddeld' => 'gemiddeld niveau, met enkele uitdagende vragen',
        'moeilijk' => 'expertniveau, met complexe vragen'
    ][$moeilijkheid] ?? 'gemiddeld niveau';
    
    $aantalVragen = min(30, max(3, intval($_POST['aantal_vragen'] ?? 10)));
    
    $prompt = <<<EOD
Maak een quiz van $aantalVragen meerkeuzevragen over '$onderwerp' ($moeilijkheidText). Geef per vraag:
- 'vraag'
- 'afbeelding_beschrijving' (zeer gedetailleerde beschrijving voor een realistische educatieve illustratie, specifiek gerelateerd aan de vraag)
- 'opties' (A, B, C, D)
- 'correcte_antwoord' (A/B/C/D)
- 'hint' (korte hint voor als de gebruiker vastloopt)

Voor de afbeelding_beschrijving:
- Beschrijf concrete objecten/scènes
- Gebruik duidelijke kleuren
- Specificeer de compositie (bijv. "close-up", "panoramisch")
- Voeg educatieve elementen toe zoals labels of diagrammen indien relevant
- Houd het wetenschappelijk accuraat

Voorbeeld van een goede beschrijving:
"Close-up van een menselijk hart met duidelijke labels van de belangrijkste onderdelen (linker- en rechterkamer, boezems, aorta) op een witte achtergrond, in realistische medische stijl"

Gebruik alleen JSON array, geen uitleg.
EOD;

    // OpenAI API-aanvraag voor vragen
    $data = [
        "model" => "gpt-4-turbo",
        "messages" => [
            ["role" => "system", "content" => "Je bent een quizgenerator. Geef alleen JSON terug."],
            ["role" => "user", "content" => $prompt]
        ],
        "temperature" => 0.7
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = "Netwerkfout: " . curl_error($ch);
    } else {
        $response = json_decode($result, true);
        $text = $response['choices'][0]['message']['content'] ?? '';
        
        // Probeer JSON te parsen
        $quiz = json_decode($text, true);

        // Fallback: haal JSON uit ```json blok
        if (!$quiz) {
            if (preg_match('/```json(.*?)```/s', $text, $matches)) {
                $quiz = json_decode(trim($matches[1]), true);
            }
        }

        if (!$quiz || !is_array($quiz)) {
            $error = "OpenAI antwoord was geen geldige JSON. Probeer een ander onderwerp.";
            error_log("OpenAI response error: " . $text);
        } else {
            // Afbeeldingen genereren indien aangevraagd
            if ($genereerAfbeeldingen) {
                foreach ($quiz as &$vraag) {
                    if (!empty($vraag['afbeelding_beschrijving'])) {
                        $imagePrompt = "Een duidelijke, realistische educatieve illustratie in hoge resolutie voor een quizvraag. ";
                        $imagePrompt .= "Toon: " . $vraag['afbeelding_beschrijving'];
                        $imagePrompt .= ". Stijl: fotorealistisch of duidelijk educatief diagram met labels. ";
                        $imagePrompt .= "Achtergrond: effen of subtiel verloop. Vermijd abstracte kunst.";
                        
                        $imageData = [
                            "model" => "dall-e-3",
                            "prompt" => $imagePrompt,
                            "n" => 1,
                            "size" => "1024x1024",
                            "quality" => "hd",
                            "style" => "natural"
                        ];

                        $imageCh = curl_init("https://api.openai.com/v1/images/generations");
                        curl_setopt($imageCh, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($imageCh, CURLOPT_HTTPHEADER, [
                            "Authorization: Bearer $apiKey",
                            "Content-Type: application/json"
                        ]);
                        curl_setopt($imageCh, CURLOPT_POSTFIELDS, json_encode($imageData));
                        $imageResult = curl_exec($imageCh);
                        
                        if (!curl_errno($imageCh)) {
                            $imageResponse = json_decode($imageResult, true);
                            $vraag['afbeelding_url'] = $imageResponse['data'][0]['url'] ?? null;
                        }
                        
                        curl_close($imageCh);
                        sleep(2); // Meer tijd tussen requests voor betere kwaliteit
                    }
                }
            }

            $_SESSION['quiz'] = $quiz;
            $_SESSION['onderwerp'] = $onderwerp;
            $_SESSION['moeilijkheid'] = $moeilijkheid;
            $_SESSION['aantal_vragen'] = $aantalVragen;
            $_SESSION['genereer_afbeeldingen'] = $genereerAfbeeldingen;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    curl_close($ch);
}

// Antwoorden verwerken
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['antwoorden'])) {
    $quiz = $_SESSION['quiz'];
    $antwoorden = $_POST['antwoorden'];
    $feedback = [];
    $score = 0;

    foreach ($quiz as $index => $vraag) {
        $juist = $vraag['correcte_antwoord'];
        $antwoord = $antwoorden[$index] ?? '';
        
        if ($antwoord === $juist) {
            $feedback[] = [
                'type' => 'correct',
                'text' => "✅ Vraag " . ((int)$index + 1) . " is juist!",
                'uitleg' => $vraag['hint'] ?? 'Goed gedaan!',
                'afbeelding_url' => $vraag['afbeelding_url'] ?? null
            ];
            $score++;
        } else {
            $feedback[] = [
                'type' => 'incorrect',
                'text' => "❌ Vraag " . ((int)$index + 1) . ": Jouw antwoord was $antwoord, juist was $juist.",
                'uitleg' => $vraag['hint'] ?? 'Probeer het nog eens!',
                'afbeelding_url' => $vraag['afbeelding_url'] ?? null
            ];
        }
    }
    
    // Sla resultaten op in sessie voor export
    $_SESSION['resultaten'] = [
        'score' => $score,
        'totaal' => count($quiz),
        'feedback' => $feedback,
        'onderwerp' => $_SESSION['onderwerp'],
        'datum' => date('d-m-Y H:i')
    ];
}

// Exporteer resultaten
if (isset($_GET['export'])) {
    if (isset($_SESSION['resultaten'])) {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="quiz_resultaten_' . date('Y-m-d') . '.json"');
        echo json_encode($_SESSION['resultaten'], JSON_PRETTY_PRINT);
        exit;
    }
}

// Nieuwe toets aanvragen
if (isset($_GET['nieuwe_toets'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
set_time_limit(300); // 300 seconden = 5 minuten
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Oefentoets Generator</title>
    <style>
        :root {
            --primary: #3a5bc7;
            --primary-dark: #2c4ab3;
            --accent: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --success: #2ecc71;
            --error: #e74c3c;
            --warning: #f39c12;
            --border-radius: 12px;
            --shadow: 0 2px 8px rgba(0,0,0,0.1);
            --text-on-primary: #ffffff;
        }
        .dark-mode {
            --primary: #5d7bd4;
            --primary-dark: #4a6bc8;
            --accent: #6fd4f8;
            --light: #1a1a2e;
            --dark: #e6e6e6;
            --gray: #a6a6a6;
            --success: #48e68b;
            --error: #ff6b5b;
            --warning: #ff9f43;
            --shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light);
            color: var(--dark);
            line-height: 1.6;
            padding: 20px;
            transition: background-color 0.3s, color 0.3s;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: var(--light);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: background-color 0.3s, box-shadow 0.3s;
        }
        h1, h2 {
            color: var(--primary);
            text-align: center;
            padding: 20px;
            background: var(--light);
            margin: 0;
            transition: background-color 0.3s, color 0.3s;
        }
        h2 {
            padding: 15px;
            font-size: 1.3em;
        }
        form { 
            background: var(--light);
            padding: 1.5em;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 2em;
            transition: background-color 0.3s;
        }
        .vraag { 
            margin-bottom: 1.5em;
            padding: 1em;
            background: var(--light);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            transition: background-color 0.3s, transform 0.3s;
        }
        .vraag:hover {
            transform: translateY(-3px);
        }
        .vraag-afbeelding {
            margin: 1em 0;
            text-align: center;
        }
        .vraag-afbeelding img, .feedback-afbeelding img {
            max-width: 100%;
            max-height: 400px;
            width: auto;
            height: auto;
            object-fit: contain;
            border: 1px solid var(--gray);
            box-shadow: var(--shadow);
            margin: 10px auto;
            display: block;
            background-color: white;
            padding: 5px;
            border-radius: var(--border-radius);
            transition: opacity 0.3s;
        }
        .opties label { 
            display: block;
            margin-bottom: 0.5em;
            padding: 0.8em;
            border-radius: var(--border-radius);
            transition: background-color 0.2s;
            cursor: pointer;
            border: 1px solid var(--gray);
        }
        .opties label:hover, .opties input:checked + label {
            background-color: rgba(58,91,199,0.1);
            border-color: var(--primary);
        }
        .opties input[type="radio"] {
            opacity: 0;
            position: absolute;
        }
        .feedback { 
            background: var(--light);
            padding: 1.5em;
            border-radius: var(--border-radius);
            margin: 1em 0;
            box-shadow: var(--shadow);
            transition: background-color 0.3s;
        }
        .feedback-item {
            padding: 1em;
            margin-bottom: 1em;
            border-radius: var(--border-radius);
            border-left: 4px solid;
        }
        .feedback-correct {
            border-left-color: var(--success);
            background-color: rgba(46,204,113,0.1);
        }
        .feedback-incorrect {
            border-left-color: var(--error);
            background-color: rgba(231,76,60,0.1);
        }
        .feedback-afbeelding {
            margin-top: 1em;
            text-align: center;
        }
        .hint {
            font-style: italic;
            margin-top: 0.5em;
            padding: 0.5em;
            background-color: rgba(255,255,255,0.2);
            border-radius: var(--border-radius);
        }
        .score { 
            font-weight: bold;
            color: var(--success);
            text-align: center;
            margin: 1em 0;
            font-size: 1.2em;
        }
        .progress-container {
            width: 100%;
            background-color: var(--gray);
            border-radius: var(--border-radius);
            margin: 1em 0;
        }
        .progress-bar {
            height: 20px;
            background-color: var(--primary);
            border-radius: var(--border-radius);
            width: 0%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
        }
        .timer {
            text-align: center;
            font-size: 1.5em;
            margin: 0.5em 0;
            color: var(--primary);
            font-weight: bold;
        }
        button, .btn {
            padding: 12px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.2s;
            margin: 0.5em 0;
        }
        button:hover, .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn-secondary {
            background: var(--light);
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        .btn-secondary:hover {
            background: var(--primary);
            color: white;
        }
        .btn-success {
            background: var(--success);
        }
        .btn-success:hover {
            background: #27ae60;
        }
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        .btn-warning:hover {
            background: #e67e22;
        }
        input[type="text"], select {
            width: 100%;
            padding: 12px;
            margin-bottom: 1em;
            font-size: 16px;
            border: 1px solid var(--gray);
            border-radius: var(--border-radius);
            transition: all 0.2s;
            background: var(--light);
            color: var(--dark);
        }
        input[type="text"]:focus, select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(58,91,199,0.2);
            outline: none;
        }
        .form-group {
            margin-bottom: 1em;
        }
        .form-row {
            display: flex;
            gap: 15px;
        }
        .form-row .form-group {
            flex: 1;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 1em;
        }
        .checkbox-group input {
            width: auto;
            margin-right: 10px;
        }
        .voorbeeld-onderwerpen {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 1em 0;
        }
        .voorbeeld-item {
            background: var(--primary);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8em;
            cursor: pointer;
            transition: all 0.2s;
        }
        .voorbeeld-item:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        .error {
            color: var(--error);
            background-color: rgba(231,76,60,0.1);
            padding: 1em;
            border-radius: var(--border-radius);
            margin-bottom: 1em;
            border-left: 4px solid var(--error);
        }
        .loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .loading-content {
            background: var(--light);
            padding: 2em;
            border-radius: var(--border-radius);
            text-align: center;
            box-shadow: var(--shadow);
        }
        .spinner {
            border: 5px solid var(--gray);
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1em;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .back-btn, .dark-mode-toggle, .scroll-top {
            position: fixed;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        .back-btn {
            bottom: 20px;
            left: 20px;
        }
        .dark-mode-toggle {
            bottom: 20px;
            right: 20px;
        }
        .scroll-top {
            bottom: 80px;
            right: 20px;
            opacity: 0;
            pointer-events: none;
        }
        .scroll-top.visible {
            opacity: 1;
            pointer-events: auto;
        }
        .back-btn:hover, .dark-mode-toggle:hover, .scroll-top:hover {
            transform: scale(1.1);
        }
        @media (max-width: 768px) {
            .container { border-radius: 0; }
            .back-btn, .dark-mode-toggle, .scroll-top {
                bottom: 10px;
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
            .back-btn { left: 10px; }
            .dark-mode-toggle, .scroll-top { right: 10px; }
            .scroll-top { bottom: 60px; }
            .btn-group {
                flex-direction: column;
                align-items: center;
            }
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            .vraag-afbeelding img, .feedback-afbeelding img {
                max-height: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧠 AI Oefentoets Generator</h1>

        <?php if (!isset($_SESSION['quiz']) && !$feedback): ?>
            <form method="post" id="quizForm">
                <?php if ($error): ?>
                    <div class="error">⚠️ <?= $error ?></div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="onderwerp">Welk onderwerp wil je oefenen?</label>
                    <input type="text" id="onderwerp" name="onderwerp" placeholder="Bijv. anatomie van het hart, planeten in ons zonnestelsel..." required>
                    <small>Kies een specifiek onderwerp voor betere vragen en afbeeldingen</small>
                    
                    <div class="voorbeeld-onderwerpen">
                        <small>Voorbeelden:</small>
                        <?php foreach ($voorbeeldOnderwerpen as $voorbeeld): ?>
                            <span class="voorbeeld-item" onclick="document.getElementById('onderwerp').value = '<?= $voorbeeld ?>'"><?= $voorbeeld ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="moeilijkheid">Moeilijkheidsgraad</label>
                        <select id="moeilijkheid" name="moeilijkheid">
                            <option value="makkelijk">Makkelijk</option>
                            <option value="gemiddeld" selected>Gemiddeld</option>
                            <option value="moeilijk">Moeilijk</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="aantal_vragen">Aantal vragen (3-30)</label>
                        <input type="number" id="aantal_vragen" name="aantal_vragen" min="3" max="30" value="10">
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="genereer_afbeeldingen" name="genereer_afbeeldingen" checked>
                    <label for="genereer_afbeeldingen">Realistische educatieve afbeeldingen genereren</label>
                </div>
                
                <div class="btn-group">
                    <button type="submit" id="generateBtn">Genereer Quiz</button>
                </div>
            </form>

        <?php elseif (isset($_SESSION['quiz']) && !$feedback): ?>
            <form method="post" id="quizForm">
                <h2>Onderwerp: <?= htmlspecialchars($_SESSION['onderwerp']) ?> 
                    <small>(<?= $_SESSION['moeilijkheid'] ?>, <?= $_SESSION['aantal_vragen'] ?> vragen)</small>
                </h2>
                
                <div class="progress-container">
                    <div class="progress-bar" id="progressBar">0%</div>
                </div>
                
                <?php if ($_SESSION['aantal_vragen'] > 5): ?>
                    <div class="timer" id="timer">10:00</div>
                <?php endif; ?>
                
                <?php $i = 1; ?>
                <?php foreach ($_SESSION['quiz'] as $index => $vraag): ?>
                    <div class="vraag" id="vraag-<?= $index ?>">
                        <p><strong><?= $i ?>. <?= htmlspecialchars($vraag['vraag'] ?? 'Geen vraag') ?></strong></p>
                        
                        <?php if (!empty($vraag['afbeelding_url']) && $_SESSION['genereer_afbeeldingen']): ?>
                            <div class="vraag-afbeelding">
                                <img src="<?= $vraag['afbeelding_url'] ?>" alt="Illustratie bij vraag" loading="lazy">
                            </div>
                        <?php endif; ?>
                        
                        <div class="opties">
                            <?php if (isset($vraag['opties']) && is_array($vraag['opties'])): ?>
                                <?php foreach ($vraag['opties'] as $letter => $optie): ?>
                                    <label>
                                        <input type="radio" name="antwoorden[<?= $index ?>]" value="<?= $letter ?>" required>
                                        <?= $letter ?>. <?= htmlspecialchars($optie) ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color:var(--error);">❌ Opties ontbreken voor deze vraag.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php $i++; ?>
                <?php endforeach; ?>
                
                <div class="btn-group">
                    <button type="submit">Verstuur Antwoorden</button>
                    <a href="?nieuwe_toets=1" class="btn btn-secondary">Nieuwe Toets</a>
                </div>
            </form>

        <?php elseif ($feedback): ?>
            <div class="feedback">
                <h2>📊 Resultaten: <?= htmlspecialchars($_SESSION['onderwerp']) ?></h2>
                <p class="score">Score: <?= $score ?> van de <?= count($_SESSION['quiz']) ?> vragen goed!</p>
                
                <?php foreach ($feedback as $item): ?>
                    <div class="feedback-item feedback-<?= $item['type'] ?>">
                        <p><?= $item['text'] ?></p>
                        <div class="hint"><?= $item['uitleg'] ?></div>
                        <?php if (!empty($item['afbeelding_url']) && $_SESSION['genereer_afbeeldingen']): ?>
                            <div class="feedback-afbeelding">
                                <img src="<?= $item['afbeelding_url'] ?>" alt="Illustratie bij feedback" loading="lazy">
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <div class="btn-group">
                    <a href="?nieuwe_toets=1" class="btn">Nieuwe Toets Maken</a>
                    <a href="?export=1" class="btn btn-success">Exporteer Resultaten</a>
                    <a href="index.php" class="btn btn-secondary">Terug naar Huiswerkhulp</a>
                </div>
            </div>
            <?php session_destroy(); ?>
        <?php endif; ?>
    </div>

    <a href="index.php" class="back-btn" aria-label="Terug naar huiswerkhulp">🏠</a>
    <button id="darkModeToggle" class="dark-mode-toggle" aria-label="Dark mode toggle">🌙</button>
    <button id="scrollTopBtn" class="scroll-top" aria-label="Terug naar boven">↑</button>

    <script>
        // Dark mode functionaliteit
        const darkModeToggle = document.getElementById('darkModeToggle');
        let darkMode = localStorage.getItem('darkMode') === 'true';

        function applyDarkMode() {
            if (darkMode) {
                document.body.classList.add('dark-mode');
                darkModeToggle.textContent = '☀️';
            } else {
                document.body.classList.remove('dark-mode');
                darkModeToggle.textContent = '🌙';
            }
        }

        function toggleDarkMode() {
            darkMode = !darkMode;
            localStorage.setItem('darkMode', darkMode);
            applyDarkMode();
        }

        darkModeToggle.addEventListener('click', toggleDarkMode);
        applyDarkMode();

        // Scroll naar boven knop
        const scrollTopBtn = document.getElementById('scrollTopBtn');
        
        window.addEventListener('scroll', () => {
            if (window.pageYOffset > 300) {
                scrollTopBtn.classList.add('visible');
            } else {
                scrollTopBtn.classList.remove('visible');
            }
        });

        scrollTopBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Voorbeeld onderwerpen klikbaar maken
        document.querySelectorAll('.voorbeeld-item').forEach(item => {
            item.addEventListener('click', function() {
                document.getElementById('onderwerp').value = this.textContent;
                document.getElementById('onderwerp').focus();
            });
        });

        // Quiz voortgang bijhouden
        if (document.getElementById('progressBar') && document.querySelectorAll('.vraag').length > 0) {
            const vragen = document.querySelectorAll('.vraag');
            const progressBar = document.getElementById('progressBar');
            
            function updateProgress() {
                const beantwoord = document.querySelectorAll('input[type="radio"]:checked').length;
                const percentage = Math.round((beantwoord / vragen.length) * 100);
                progressBar.style.width = `${percentage}%`;
                progressBar.textContent = `${percentage}%`;
                
                // Markeer vragen die beantwoord zijn
                vragen.forEach((vraag, index) => {
                    if (document.querySelector(`input[name="antwoorden[${index}]"]:checked`)) {
                        vraag.style.borderLeft = '4px solid var(--success)';
                    } else {
                        vraag.style.borderLeft = '4px solid var(--gray)';
                    }
                });
            }
            
            document.querySelectorAll('input[type="radio"]').forEach(radio => {
                radio.addEventListener('change', updateProgress);
            });
            
            updateProgress();
        }

        // Timer functionaliteit voor lange quizzes
        if (document.getElementById('timer')) {
            let timeLeft = 600; // 10 minuten in seconden
            const timerElement = document.getElementById('timer');
            
            const timer = setInterval(() => {
                timeLeft--;
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerElement.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
                
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    alert("Tijd is om! Je antwoorden worden automatisch ingediend.");
                    document.getElementById('quizForm').submit();
                }
                
                // Waarschuwing bij 1 minuut over
                if (timeLeft === 60) {
                    timerElement.style.color = 'var(--warning)';
                }
            }, 1000);
        }

        // Laadstatus tonen bij formulier indienen
        document.getElementById('quizForm')?.addEventListener('submit', function() {
            document.body.insertAdjacentHTML('beforeend', `
                <div class="loading" id="loading">
                    <div class="loading-content">
                        <div class="spinner"></div>
                        <p>Quiz aan het genereren...</p>
                        <small>Dit kan even duren, afhankelijk van het onderwerp en afbeeldingen.</small>
                    </div>
                </div>
            `);
        });

        // Bewaar antwoorden in localStorage
        document.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const answers = JSON.parse(localStorage.getItem('quizAnswers') || '{}');
                answers[this.name] = this.value;
                localStorage.setItem('quizAnswers', JSON.stringify(answers));
            });
        });

        // Herlaad opgeslagen antwoorden bij pagina laden
        window.addEventListener('load', function() {
            const answers = JSON.parse(localStorage.getItem('quizAnswers') || '{}');
            for (const name in answers) {
                const radio = document.querySelector(`input[name="${name}"][value="${answers[name]}"]`);
                if (radio) radio.checked = true;
            }
            
            // Verwijder opgeslagen antwoorden als de quiz is ingediend
            if (window.location.search.includes('nieuwe_toets') || <?= $feedback ? 'true' : 'false' ?>) {
                localStorage.removeItem('quizAnswers');
            }
            
            // Update progress bar na laden
            if (typeof updateProgress === 'function') {
                updateProgress();
            }

            // Voeg fade-in effect toe voor afbeeldingen
            document.querySelectorAll('img').forEach(img => {
                img.onload = function() {
                    this.style.opacity = 1;
                };
                img.style.transition = 'opacity 0.3s';
                img.style.opacity = 0;
            });
        });
    </script>
</body>
</html>