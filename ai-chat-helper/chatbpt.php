<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

// Taalinstelling (Nederlands als standaard)
$taal = $_SESSION['taal'] ?? 'nl';
if (isset($_GET['taal']) {
    $taal = in_array($_GET['taal'], ['nl', 'en']) ? $_GET['taal'] : 'nl';
    $_SESSION['taal'] = $taal;
}

// Nederlandse en Engelse vertalingen
$teksten = [
    'nl' => [
        'titel' => 'AI Chat Assistant',
        'welkom' => 'Hallo! Stel me een vraag.',
        'plaatshouder' => 'Typ je bericht...',
        'versturen' => 'Versturen',
        'wissen' => 'Wis geschiedenis',
        'fout' => 'Er ging iets mis. Probeer later opnieuw.',
        'api_fout' => 'API-fout: ongeldige sleutel of netwerkprobleem'
    ],
    'en' => [
        'titel' => 'AI Chat Assistant',
        'welkom' => 'Hello! Ask me a question.',
        'plaatshouder' => 'Type your message...',
        'versturen' => 'Send',
        'wissen' => 'Clear history',
        'fout' => 'Something went wrong. Try again later.',
        'api_fout' => 'API error: invalid key or network issue'
    ]
];

// OpenAI API aanroep (veilig via .env)
function callOpenAI($bericht) {
    $sleutel = $_ENV['OPENAI_API_KEY'] ?? '';
    if (empty($sleutel)) return false;

    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [['role' => 'user', 'content' => $bericht]],
        'temperature' => 0.7
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $sleutel,
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);

    $antwoord = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($status === 200) ? json_decode($antwoord, true)['choices'][0]['message']['content'] : false;
}

// Formulier verwerking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['bericht'])) {
        $reactie = callOpenAI($_POST['bericht']);
        echo $reactie ?: $teksten[$taal]['api_fout'];
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= $taal ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $teksten[$taal]['titel'] ?></title>
    <style>
        /* [Behoud je CSS uit eerdere versies] */
        /* Voeg toe: */
        .taal-kiezer {
            position: fixed;
            top: 20px;
            right: 80px;
            padding: 5px;
            border-radius: 4px;
            background: var(--container-bg);
            border: 1px solid var(--border-color);
        }
    </style>
</head>
<body>
    <select class="taal-kiezer" onchange="window.location.href='?taal='+this.value">
        <option value="nl" <?= $taal === 'nl' ? 'selected' : '' ?>>🇳🇱 Nederlands</option>
        <option value="en" <?= $taal === 'en' ? 'selected' : '' ?>>🇬🇧 English</option>
    </select>
    
    <button id="darkModeToggle">🌙</button>
    <div class="container">
        <h1><?= $teksten[$taal]['titel'] ?></h1>
        
        <div class="chat-container" id="chatContainer">
            <div class="message bot-message"><?= $teksten[$taal]['welkom'] ?></div>
        </div>
        
        <div class="input-area">
            <input type="text" id="userInput" placeholder="<?= $teksten[$taal]['plaatshouder'] ?>">
            <button id="sendBtn"><?= $teksten[$taal]['versturen'] ?></button>
            <button id="clearBtn"><?= $teksten[$taal]['wissen'] ?></button>
        </div>
    </div>

    <script>
        // Chatfuncties (aangepast voor taal)
        async function verstuurBericht() {
            const bericht = document.getElementById('userInput').value.trim();
            if (!bericht) return;

            // UI-update
            const chatContainer = document.getElementById('chatContainer');
            chatContainer.innerHTML += `
                <div class="message user-message">${bericht}</div>
                <div class="message bot-message typing" id="typingIndicator">
                    <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                </div>
            `;
            document.getElementById('userInput').value = '';
            chatContainer.scrollTop = chatContainer.scrollHeight;

            // Verstuur naar server
            try {
                const response = await fetch('chatbot.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `bericht=${encodeURIComponent(bericht)}`
                });
                const tekst = await response.text();
                
                document.getElementById('typingIndicator').outerHTML = `
                    <div class="message bot-message">${tekst || '<?= $teksten[$taal]['fout'] ?>'}</div>
                `;
            } catch (error) {
                console.error("Fout:", error);
            }
        }

        // Event listeners
        document.getElementById('sendBtn').addEventListener('click', verstuurBericht);
        document.getElementById('userInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') verstuurBericht();
        });
        document.getElementById('clearBtn').addEventListener('click', () => {
            document.getElementById('chatContainer').innerHTML = `
                <div class="message bot-message"><?= $teksten[$taal]['welkom'] ?></div>
            `;
        });
    </script>
</body>
</html>

<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions');
$ENV_FILE = __DIR__ . '/.env';
$UPLOAD_DIR = __DIR__ . '/uploads/';
$MAX_FILE_SIZE = 5 * 1024 * 1024;
$ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];

// Internet check
function isInternetConnected() {
    $connected = @fsockopen("www.google.com", 80, $errno, $errstr, 2); 
    if ($connected) {
        fclose($connected);
        return true;
    }
    return false;
}

// Create upload directory if not exists
if (!file_exists($UPLOAD_DIR)) {
    if (!mkdir($UPLOAD_DIR, 0755, true)) {
        die("Kon upload directory niet aanmaken");
    }
}

// Load environment variables
function loadEnv() {
    global $ENV_FILE;
    if (!file_exists($ENV_FILE)) {
        die(".env bestand niet gevonden");
    }
    $lines = file($ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
loadEnv();

// Handle file uploads
function handleUploadedFile($file) {
    global $UPLOAD_DIR, $MAX_FILE_SIZE, $ALLOWED_TYPES;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload fout: " . $file['error']);
    }
    if ($file['size'] > $MAX_FILE_SIZE) {
        throw new Exception("Bestand is te groot (max 5MB)");
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime, $ALLOWED_TYPES)) {
        throw new Exception("Ongeldig bestandstype. Alleen JPEG, PNG, GIF en PDF zijn toegestaan");
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('file_') . '.' . $ext;
    $destination = $UPLOAD_DIR . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception("Kon bestand niet opslaan");
    }
    
    return [
        'path' => $destination,
        'url' => 'uploads/' . $filename,
        'mime' => $mime,
        'name' => $file['name']
    ];
}

// Call OpenAI API
function callOpenAI($message, $files = []) {
    if (!isInternetConnected()) {
        throw new Exception("Geen internetverbinding - kan AI niet bereiken");
    }

    if (empty($_ENV['OPENAI_API_KEY'])) {
        throw new Exception("OpenAI API key niet geconfigureerd");
    }

    $messages = $_SESSION['chat_history'] ?? [];
    if (empty($messages)) {
        $messages[] = [
            'role' => 'system',
            'content' => 'Je bent een AI-huiswerkhulp. Geef duidelijke, gestructureerde antwoorden met belangrijke informatie in **vetgedrukt**. Voor rekenvragen: geef eerst het antwoord in **vet**, gevolgd door uitleg.'
        ];
    }
    
    $content = [['type' => 'text', 'text' => $message]];
    foreach ($files as $file) {
        if (strpos($file['mime'], 'image/') === 0) {
            $imageData = base64_encode(file_get_contents($file['path']));
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$file['mime']};base64,{$imageData}"
                ]
            ];
        } elseif ($file['mime'] === 'application/pdf') {
            $textContent = shell_exec('pdftotext ' . escapeshellarg($file['path']) . ' -');
            $content[] = [
                'type' => 'text',
                'text' => "Inhoud van PDF " . $file['name'] . ":\n" . ($textContent ?: "Kon PDF-inhoud niet extraheren")
            ];
        }
    }
    
    $messages[] = ['role' => 'user', 'content' => $content];
    
    $data = [
        'model' => 'gpt-4-turbo',
        'messages' => $messages,
        'max_tokens' => 3000,
        'temperature' => 0.7
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENAI_API_URL,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $_ENV['OPENAI_API_KEY'],
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("API Error $httpCode: " . $response);
        throw new Exception("API request mislukt: " . ($curlError ?: "HTTP $httpCode"));
    }
    
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Ongeldig API antwoord");
    }
    
    if (empty($result['choices'][0]['message']['content'])) {
        throw new Exception("Leeg antwoord van API");
    }
    
    $_SESSION['chat_history'] = $messages;
    $_SESSION['chat_history'][] = $result['choices'][0]['message'];
    
    return $result['choices'][0]['message']['content'];
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['status' => 'error', 'message' => 'Onbekende fout'];
    try {
        if (!isset($_POST['action'])) {
            throw new Exception("Geen actie gespecificeerd");
        }
        
        switch ($_POST['action']) {
            case 'send':
                $message = $_POST['message'] ?? '';
                $files = [];
                
                if (!empty($_FILES['files']['tmp_name'][0])) {
                    foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
                            $file = [
                                'name' => $_FILES['files']['name'][$key],
                                'type' => $_FILES['files']['type'][$key],
                                'tmp_name' => $tmp_name,
                                'error' => $_FILES['files']['error'][$key],
                                'size' => $_FILES['files']['size'][$key]
                            ];
                            $files[] = handleUploadedFile($file);
                        }
                    }
                }
                
                if (!isInternetConnected()) {
                    throw new Exception("Geen internetverbinding - kan geen AI-antwoord genereren");
                }
                
                $reply = callOpenAI($message, $files);
                $response = [
                    'status' => 'success',
                    'reply' => $reply
                ];
                break;
                
            case 'clear':
                $_SESSION['chat_history'] = [];
                $response = ['status' => 'success'];
                break;
                
            case 'suggest':
                $query = $_POST['query'] ?? '';
                $suggestions = [
                    "Hoe werkt fotosynthese?",
                    "Los deze wiskunde opgave op: 3x + 5 = 20",
                    "Wat is de hoofdstad van Frankrijk?",
                    "Leg het verschil uit tussen een werkwoord en een zelfstandig naamwoord"
                ];
                $response = [
                    'status' => 'success',
                    'suggestions' => array_filter($suggestions, function($item) use ($query) {
                        return stripos($item, $query) !== false;
                    })
                ];
                break;
                
            default:
                throw new Exception("Ongeldige actie");
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        error_log("Fout: " . $e->getMessage());
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$noInternet = !isInternetConnected();
$chatHasContent = !empty($_SESSION['chat_history']);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Huiswerkhulp</title>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --accent: #4cc9f0;
            --secondary: #7209b7;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #adb5bd;
            --success: #2ecc71;
            --error: #ef233c;
            --warning: #ff9e00;
            --border-radius: 10px;
            --shadow: 0 4px 6px rgba(0,0,0,0.1);
            --text-on-primary: #ffffff;
            --card-bg: #ffffff;
        }

        .dark-mode {
            --primary: #4895ef;
            --primary-dark: #4361ee;
            --accent: #4cc9f0;
            --secondary: #b5179e;
            --light: #121212;
            --dark: #e8e8e8;
            --gray: #6c757d;
            --success: #4caf50;
            --error: #f44336;
            --warning: #ff9800;
            --shadow: 0 4px 6px rgba(0,0,0,0.3);
            --card-bg: #1e1e1e;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: var(--light);
            color: var(--dark);
            line-height: 1.6;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.1);
        }

        h1 {
            color: var(--primary);
            text-align: center;
            padding: 25px;
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .onboarding-tips {
            background: rgba(67, 97, 238, 0.1);
            padding: 20px;
            margin: 0 20px 20px;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary);
        }

        .onboarding-tips p {
            font-weight: 600;
            margin-bottom: 12px;
            color: var(--primary);
            font-size: 1.1rem;
        }

        .onboarding-tips ul {
            padding-left: 25px;
            color: var(--gray);
        }

        .onboarding-tips li {
            margin-bottom: 8px;
            position: relative;
            line-height: 1.5;
        }

        .onboarding-tips li:before {
            content: "•";
            color: var(--primary);
            font-weight: bold;
            display: inline-block;
            width: 1em;
            margin-left: -1em;
        }

        .chat-container {
            height: 65vh;
            overflow-y: auto;
            padding: 25px;
            background: var(--card-bg);
            scroll-behavior: smooth;
            background-image: 
                radial-gradient(circle at 1px 1px, rgba(0,0,0,0.1) 1px, transparent 0);
            background-size: 20px 20px;
        }

        .message {
            margin-bottom: 16px;
            padding: 16px 20px;
            border-radius: var(--border-radius);
            max-width: 85%;
            line-height: 1.5;
            position: relative;
            animation: fadeIn 0.3s ease-out;
            font-size: 1rem;
            box-shadow: var(--shadow);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .user-message {
            background: var(--primary);
            color: var(--text-on-primary);
            margin-left: auto;
            border-bottom-right-radius: 4px;
        }

        .bot-message {
            background: var(--card-bg);
            margin-right: auto;
            border-bottom-left-radius: 4px;
            border: 1px solid rgba(0,0,0,0.1);
        }

        .message img, .message embed {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            margin-top: 15px;
            display: block;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .input-area {
            display: flex;
            gap: 12px;
            padding: 20px;
            background: var(--card-bg);
            flex-wrap: wrap;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .input-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 200px;
            position: relative;
        }

        #userInput {
            flex: 1;
            padding: 14px;
            border: 2px solid var(--gray);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.2s;
            background: var(--card-bg);
            color: var(--dark);
            resize: none;
            min-height: 60px;
        }

        #userInput:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            outline: none;
        }

        .suggestions-container {
            position: absolute;
            bottom: 100%;
            width: 100%;
            background: var(--card-bg);
            border: 1px solid var(--gray);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            z-index: 100;
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }

        .suggestion-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .suggestion-item:hover {
            background: rgba(67, 97, 238, 0.1);
        }

        button {
            padding: 14px 24px;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        #sendBtn {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 4px rgba(67, 97, 238, 0.3);
        }

        #sendBtn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(67, 97, 238, 0.3);
        }

        #sendBtn:disabled {
            background: var(--gray);
            transform: none;
            box-shadow: none;
            cursor: not-allowed;
            opacity: 0.7;
        }

        #clearBtn {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        #clearBtn:hover {
            background: rgba(67, 97, 238, 0.1);
        }

        #scanBtn {
            background: transparent;
            color: var(--secondary);
            border: 2px solid var(--secondary);
        }

        #scanBtn:hover {
            background: var(--secondary);
            color: white;
        }

        #quizBtn {
            background: transparent;
            color: var(--success);
            border: 2px solid var(--success);
        }

        #quizBtn:hover {
            background: var(--success);
            color: white;
        }

        #imageUpload {
            display: none;
        }

        .upload-btn {
            padding: 14px;
            background: transparent;
            color: var(--primary);
            border-radius: var(--border-radius);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            border: 2px solid var(--primary);
            transition: all 0.2s;
        }

        .upload-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .file-previews {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }

        .file-preview {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            border: 2px solid var(--gray);
            object-fit: cover;
            transition: all 0.2s;
            position: relative;
        }

        .file-preview:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .file-remove {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--error);
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            cursor: pointer;
        }

        .typing-indicator {
            display: inline-flex;
            gap: 8px;
            padding: 16px;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 16px;
            border: 1px solid rgba(0,0,0,0.1);
        }

        .typing-dot {
            width: 10px;
            height: 10px;
            background: var(--primary);
            border-radius: 50%;
            opacity: 0.6;
            animation: bounce 1.4s infinite ease-in-out;
        }

        @keyframes bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.6; }
            30% { transform: translateY(-5px); opacity: 1; }
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        .spinner {
            width: 18px;
            height: 18px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            display: inline-block;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .user-error {
            position: fixed;
            bottom: 25px;
            right: 25px;
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            max-width: 350px;
            z-index: 1000;
            border-left: 4px solid var(--error);
            animation: slideIn 0.3s ease-out;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .user-error p {
            margin: 0;
            font-size: 0.95rem;
        }

        .user-error button {
            background: var(--error);
            color: white;
            padding: 10px 16px;
            font-size: 0.9rem;
            margin-top: 8px;
            align-self: flex-end;
        }

        .progress-bar-container {
            width: 100%;
            background: rgba(0,0,0,0.1);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .progress-bar {
            height: 4px;
            background: var(--primary);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .dark-mode-toggle {
            position: fixed;
            bottom: 25px;
            left: 25px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            width: 56px;
            height: 56px;
            font-size: 22px;
            cursor: pointer;
            box-shadow: var(--shadow);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .dark-mode-toggle:hover {
            transform: scale(1.1);
        }

        .no-internet-banner {
            background-color: rgba(239, 35, 60, 0.15);
            border-left: 4px solid var(--error);
            padding: 15px;
            margin: 0 20px 20px;
            border-radius: var(--border-radius);
            text-align: center;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .no-internet-banner p {
            margin: 0;
            color: var(--error);
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            flex-direction: column;
            gap: 20px;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 5px solid rgba(255,255,255,0.1);
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .loading-text {
            color: white;
            font-size: 1.2rem;
            font-weight: 500;
        }

        /* Markdown styling in messages */
        .message strong {
            color: var(--primary);
            font-weight: 700;
        }

        .message a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .message a:hover {
            text-decoration: underline;
        }

        .message code {
            background: rgba(0,0,0,0.1);
            padding: 2px 4px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.9em;
        }

        .message pre {
            background: rgba(0,0,0,0.1);
            padding: 12px;
            border-radius: 6px;
            overflow-x: auto;
            margin: 10px 0;
            font-family: monospace;
            font-size: 0.9em;
            line-height: 1.4;
        }

        /* Tooltip for buttons */
        [tooltip] {
            position: relative;
        }

        [tooltip]:hover:after {
            content: attr(tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--dark);
            color: var(--light);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            z-index: 100;
            margin-bottom: 8px;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .container {
                border-radius: 8px;
            }
            
            h1 {
                font-size: 1.5rem;
                padding: 20px 15px;
            }
            
            .chat-container {
                height: 60vh;
                padding: 15px;
            }
        
            .message {
                max-width: 90%;
                padding: 12px 16px;
                font-size: 0.95rem;
            }
            
            .input-area {
                flex-direction: column;
                padding: 15px;
                gap: 10px;
            }
            
            #userInput {
                margin-bottom: 0;
            }
            
            .upload-btn, #scanBtn, #quizBtn, #sendBtn, #clearBtn {
                width: 100%;
                margin-bottom: 0;
                justify-content: center;
            }
            
            .dark-mode-toggle {
                bottom: 15px;
                left: 15px;
                width: 48px;
                height: 48px;
                font-size: 18px;
            }
        }

        @media (max-width: 480px) {
            .chat-container {
                height: 55vh;
                padding: 12px;
            }
            
            .message {
                max-width: 95%;
                padding: 10px 14px;
                font-size: 0.9rem;
            }
            
            .onboarding-tips {
                padding: 15px;
                margin: 0 12px 12px;
            }
            
            .no-internet-banner {
                margin: 0 12px 12px;
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>AI Huiswerkhulp</h1>
        
        <?php if ($noInternet): ?>
        <div class="no-internet-banner">
            <p>⚠️ Geen internetverbinding - beperkte functionaliteit</p>
        </div>
        <?php endif; ?>
        
        <?php if (empty($_SESSION['chat_history'])): ?>
        <div class="onboarding-tips">
            <p>Probeer bijvoorbeeld:</p>
            <ul>
                <li>"Leg uit hoe fotosynthese werkt"</li>
                <li>"Help me met deze wiskunde opgave"</li>
                <li>"Wat is 8x7?"</li>
            </ul>
        </div>
        <?php endif; ?>
        
        <div class="chat-container" id="chatContainer">
            <?php if (!empty($_SESSION['chat_history'])): ?>
                <?php foreach ($_SESSION['chat_history'] as $msg): ?>
                    <?php if ($msg['role'] !== 'system'): ?>
                        <div class="message <?= $msg['role'] === 'user' ? 'user-message' : 'bot-message' ?>">
                            <?php if (is_array($msg['content'])): ?>
                                <?php foreach ($msg['content'] as $content): ?>
                                    <?php if ($content['type'] === 'text'): ?>
                                        <?= nl2br(htmlspecialchars($content['text'])) ?>
                                    <?php elseif (isset($content['image_url'])): ?>
                                        <img src="<?= htmlspecialchars($content['image_url']['url']) ?>" alt="Uploaded image">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars($msg['content'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="message bot-message">
                    Hallo! Stel me een vraag of upload studiemateriaal voor hulp met je huiswerk.
                </div>
            <?php endif; ?>
        </div>
        
        <div class="input-area">
            <div class="input-group">
                <textarea id="userInput" placeholder="Typ je vraag..." <?= $noInternet ? 'disabled' : '' ?>></textarea>
                <div class="suggestions-container" id="suggestionsContainer"></div>
                <div class="file-previews" id="filePreviews"></div>
                <div class="progress-bar-container" id="progressBarContainer" style="display: none;">
                    <div class="progress-bar" id="progressBar"></div>
                </div>
            </div>
            
            <label class="upload-btn" <?= $noInternet ? 'style="opacity:0.5;pointer-events:none;"' : '' ?> tooltip="Ondersteunde formaten: JPG, PNG, PDF">
                📁 Upload
                <input type="file" id="imageUpload" accept=".jpg,.jpeg,.png,.pdf" multiple <?= $noInternet ? 'disabled' : '' ?>>
            </label>
            
            <button id="scanBtn" <?= $noInternet ? 'disabled' : '' ?> tooltip="Scan een document met je camera">📸 Scan</button>
            <button id="quizBtn" tooltip="Maak een oefentoets">✏️ Oefentoets</button>
            <button id="sendBtn" <?= $noInternet ? 'disabled' : '' ?>>Versturen</button>
            <button id="clearBtn">Wis</button>
        </div>
    </div>

    <button id="darkModeToggle" class="dark-mode-toggle">🌙</button>

    <script>
        // DOM elements
        const chatContainer = document.getElementById('chatContainer');
        const userInput = document.getElementById('userInput');
        const sendBtn = document.getElementById('sendBtn');
        const clearBtn = document.getElementById('clearBtn');
        const scanBtn = document.getElementById('scanBtn');
        const quizBtn = document.getElementById('quizBtn');
        const imageUpload = document.getElementById('imageUpload');
        const filePreviews = document.getElementById('filePreviews');
        const suggestionsContainer = document.getElementById('suggestionsContainer');
        const progressBarContainer = document.getElementById('progressBarContainer');
        const progressBar = document.getElementById('progressBar');
        const darkModeToggle = document.getElementById('darkModeToggle');
        
        // State
        let uploadedFiles = [];
        let darkMode = localStorage.getItem('darkMode') === 'true';
        const noInternet = <?= $noInternet ? 'true' : 'false' ?>;
        let chatHasContent = <?= $chatHasContent ? 'true' : 'false' ?>;
        
        // Initialize
        if (darkMode) {
            document.body.classList.add('dark-mode');
            darkModeToggle.textContent = '☀️';
        }
        
        // Dark mode toggle
        darkModeToggle.addEventListener('click', () => {
            darkMode = !darkMode;
            document.body.classList.toggle('dark-mode');
            darkModeToggle.textContent = darkMode ? '☀️' : '🌙';
            localStorage.setItem('darkMode', darkMode);
        });
        
        // Add message to chat
        function addMessage(text, sender) {
            const msg = document.createElement('div');
            msg.className = `message ${sender}-message`;
            
            // Format markdown
            text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
            text = text.replace(/`(.*?)`/g, '<code>$1</code>');
            text = text.replace(/```([\s\S]*?)```/g, '<pre>$1</pre>');
            text = text.replace(/\n/g, '<br>');
            
            msg.innerHTML = text;
            chatContainer.appendChild(msg);
            chatContainer.scrollTop = chatContainer.scrollHeight;
            chatHasContent = true;
        }
        
        // Show typing indicator
        function showTyping() {
            const typing = document.createElement('div');
            typing.id = 'typingIndicator';
            typing.className = 'message bot-message typing-indicator';
            typing.innerHTML = `
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div style="margin-left: 10px;">Denkt na...</div>
            `;
            chatContainer.appendChild(typing);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }
        
        // Hide typing indicator
        function hideTyping() {
            const typing = document.getElementById('typingIndicator');
            if (typing) typing.remove();
        }
        
        // Show loading overlay
        function showLoading() {
            const overlay = document.createElement('div');
            overlay.className = 'loading-overlay';
            overlay.innerHTML = `
                <div class="loading-spinner"></div>
                <div class="loading-text">Bezig met verwerken...</div>
            `;
            document.body.appendChild(overlay);
            return overlay;
        }
        
        // Hide loading overlay
        function hideLoading(overlay) {
            if (overlay && overlay.parentNode) {
                overlay.remove();
            }
        }
        
        // Show error message
        function showError(message, persistent = false) {
            const errorBox = document.createElement('div');
            errorBox.className = 'user-error';
            if (persistent) errorBox.classList.add('persistent-error');
            errorBox.innerHTML = `
                <p>Er is een fout opgetreden:</p>
                <p>${message}</p>
                <button onclick="this.parentElement.remove()">OK</button>
            `;
            document.body.appendChild(errorBox);
            if (!persistent) {
                setTimeout(() => errorBox.remove(), 5000);
            }
        }
        
        // Update progress bar
        function updateProgressBar(percent) {
            progressBar.style.width = `${percent}%`;
            progressBarContainer.style.display = 'block';
            if (percent >= 100) {
                setTimeout(() => {
                    progressBarContainer.style.display = 'none';
                }, 500);
            }
        }
        
        // Handle file upload previews
        imageUpload.addEventListener('change', function(e) {
            filePreviews.innerHTML = '';
            uploadedFiles = [];
            
            // Show PDF tooltip if PDF is selected
            const hasPDF = Array.from(e.target.files).some(f => f.type === 'application/pdf');
            if (hasPDF) {
                const tooltip = document.createElement('div');
                tooltip.className = 'pdf-tooltip';
                tooltip.textContent = 'Tip: PDFs met duidelijke tekst werken het beste';
                filePreviews.appendChild(tooltip);
                setTimeout(() => tooltip.remove(), 5000);
            }
            
            for (let file of e.target.files) {
                if (!file.type.match('image.*|application/pdf')) continue;
                
                const previewItem = document.createElement('div');
                previewItem.className = 'file-preview-item';
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.createElement('div');
                    preview.style.position = 'relative';
                    
                    if (file.type.match('image.*')) {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'file-preview';
                        preview.appendChild(img);
                    } else {
                        const doc = document.createElement('div');
                        doc.textContent = '📄 ' + file.name.substring(0, 10) + (file.name.length > 10 ? '...' : '');
                        doc.style.padding = '10px';
                        doc.style.background = 'rgba(67, 97, 238, 0.1)';
                        doc.style.borderRadius = '4px';
                        preview.appendChild(doc);
                    }
                    
                    const removeBtn = document.createElement('div');
                    removeBtn.className = 'file-remove';
                    removeBtn.innerHTML = '×';
                    removeBtn.addEventListener('click', () => {
                        previewItem.remove();
                        uploadedFiles = uploadedFiles.filter(f => f.name !== file.name);
                    });
                    preview.appendChild(removeBtn);
                    
                    previewItem.appendChild(preview);
                    filePreviews.appendChild(previewItem);
                    uploadedFiles.push(file);
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Get suggestions as user types
        userInput.addEventListener('input', debounce(async function(e) {
            const query = e.target.value.trim();
            if (query.length < 3) {
                suggestionsContainer.style.display = 'none';
                return;
            }
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=suggest&query=${encodeURIComponent(query)}`
                });
                
                const data = await response.json();
                if (data.status === 'success' && data.suggestions.length > 0) {
                    suggestionsContainer.innerHTML = '';
                    data.suggestions.forEach(suggestion => {
                        const item = document.createElement('div');
                        item.className = 'suggestion-item';
                        item.textContent = suggestion;
                        item.addEventListener('click', () => {
                            userInput.value = suggestion;
                            suggestionsContainer.style.display = 'none';
                            userInput.focus();
                        });
                        suggestionsContainer.appendChild(item);
                    });
                    suggestionsContainer.style.display = 'block';
                } else {
                    suggestionsContainer.style.display = 'none';
                }
            } catch (error) {
                console.error('Error fetching suggestions:', error);
            }
        }, 300));
        
        // Hide suggestions when clicking outside
        document.addEventListener('click', (e) => {
            if (!suggestionsContainer.contains(e.target) && e.target !== userInput) {
                suggestionsContainer.style.display = 'none';
            }
        });
        
        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }
        
        // Handle scan button
        scanBtn.addEventListener('click', async function() {
            if (noInternet) {
                showError('Geen internetverbinding - scannen niet mogelijk');
                return;
            }
            
            const originalText = scanBtn.innerHTML;
            scanBtn.innerHTML = '<span class="spinner"></span> Scannen...';
            scanBtn.disabled = true;
            
            try {
                // Simulate scan (in a real app, this would use the device camera)
                const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                
                // Create scan overlay
                const scanOverlay = document.createElement('div');
                scanOverlay.style.position = 'fixed';
                scanOverlay.style.top = '0';
                scanOverlay.style.left = '0';
                scanOverlay.style.width = '100%';
                scanOverlay.style.height = '100%';
                scanOverlay.style.background = 'rgba(0,0,0,0.8)';
                scanOverlay.style.zIndex = '9998';
                scanOverlay.style.display = 'flex';
                scanOverlay.style.flexDirection = 'column';
                scanOverlay.style.alignItems = 'center';
                scanOverlay.style.justifyContent = 'center';
                scanOverlay.style.gap = '20px';
                
                const video = document.createElement('video');
                video.autoplay = true;
                video.srcObject = stream;
                video.style.maxWidth = '90%';
                video.style.maxHeight = '70vh';
                video.style.borderRadius = '10px';
                
                const captureBtn = document.createElement('button');
                captureBtn.textContent = '📷 Foto maken';
                captureBtn.style.padding = '15px 30px';
                captureBtn.style.fontSize = '1.2rem';
                captureBtn.style.background = 'var(--primary)';
                captureBtn.style.color = 'white';
                captureBtn.style.border = 'none';
                captureBtn.style.borderRadius = '10px';
                captureBtn.style.cursor = 'pointer';
                
                const cancelBtn = document.createElement('button');
                cancelBtn.textContent = '❌ Annuleren';
                cancelBtn.style.padding = '10px 20px';
                cancelBtn.style.background = 'transparent';
                cancelBtn.style.color = 'white';
                cancelBtn.style.border = '1px solid white';
                cancelBtn.style.borderRadius = '10px';
                cancelBtn.style.cursor = 'pointer';
                
                scanOverlay.appendChild(video);
                scanOverlay.appendChild(captureBtn);
                scanOverlay.appendChild(cancelBtn);
                document.body.appendChild(scanOverlay);
                
                // Handle capture
                captureBtn.addEventListener('click', () => {
                    const canvas = document.createElement('canvas');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                    
                    // Stop stream
                    stream.getTracks().forEach(track => track.stop());
                    
                    // Add captured image to previews
                    canvas.toBlob((blob) => {
                        const file = new File([blob], 'scan.png', { type: 'image/png' });
                        const fileInput = new DataTransfer();
                        fileInput.items.add(file);
                        imageUpload.files = fileInput.files;
                        
                        // Trigger change event
                        const event = new Event('change');
                        imageUpload.dispatchEvent(event);
                    }, 'image/png');
                    
                    // Remove overlay
                    scanOverlay.remove();
                    scanBtn.innerHTML = originalText;
                    scanBtn.disabled = false;
                });
                
                // Handle cancel
                cancelBtn.addEventListener('click', () => {
                    stream.getTracks().forEach(track => track.stop());
                    scanOverlay.remove();
                    scanBtn.innerHTML = originalText;
                    scanBtn.disabled = false;
                });
                
            } catch (error) {
                showError('Scannen mislukt: ' + error.message);
                scanBtn.innerHTML = originalText;
                scanBtn.disabled = false;
            }
        });
        
        // Handle quiz button
        quizBtn.addEventListener('click', function() {
            window.location.href = 'oefentoets.php';
        });
        
        // Send message
        async function sendMessage() {
            const message = userInput.value.trim();
            if (!message && uploadedFiles.length === 0) {
                showError('Voer een bericht in of selecteer een bestand');
                return;
            }
            
            // Add user message to chat
            if (message) addMessage(message, 'user');
            
            // Add file previews to chat
            if (uploadedFiles.length > 0) {
                const filesMsg = document.createElement('div');
                filesMsg.className = 'message user-message';
                
                if (uploadedFiles[0].type.match('image.*')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        filesMsg.innerHTML = `<img src="${e.target.result}" class="file-preview" style="max-width:100%">`;
                    };
                    reader.readAsDataURL(uploadedFiles[0]);
                } else {
                    filesMsg.textContent = '📄 ' + uploadedFiles[0].name;
                }
                
                chatContainer.appendChild(filesMsg);
            }
            
            // Clear input
            userInput.value = '';
            filePreviews.innerHTML = '';
            suggestionsContainer.style.display = 'none';
            
            // Show typing indicator
            showTyping();
            sendBtn.disabled = true;
            
            try {
                const formData = new FormData();
                formData.append('action', 'send');
                if (message) formData.append('message', message);
                
                for (let file of uploadedFiles) {
                    formData.append('files[]', file);
                }
                
                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        updateProgressBar(percent);
                    }
                });
                
                xhr.open('POST', '');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        const data = JSON.parse(xhr.responseText);
                        if (data.status === 'success' && data.reply) {
                            addMessage(data.reply, 'bot');
                        } else if (data.message) {
                            showError(data.message, true);
                        }
                    } else {
                        showError('Netwerkfout: ' + xhr.statusText, true);
                    }
                };
                
                xhr.onerror = function() {
                    showError('Er is een netwerkfout opgetreden', true);
                };
                
                xhr.send(formData);
                
            } catch (error) {
                showError(error.message, true);
            } finally {
                hideTyping();
                sendBtn.disabled = false;
                uploadedFiles = [];
            }
        }
        
        // Clear chat history
        clearBtn.addEventListener('click', async function() {
            if (!chatHasContent) return;
            
            if (!confirm('Weet je zeker dat je de chatgeschiedenis wilt wissen?')) {
                return;
            }
            
            const overlay = showLoading();
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=clear'
                });
                
                const data = await response.json();
                if (data.status !== 'success') throw new Error(data.message);
                
                // Reload the page to show fresh chat
                location.reload();
                
            } catch (error) {
                showError(error.message, true);
            } finally {
                hideLoading(overlay);
            }
        });
        
        // Event listeners
        sendBtn.addEventListener('click', sendMessage);
        userInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        // Warn before leaving page with unsaved content
        window.addEventListener('beforeunload', (e) => {
            if (chatHasContent) {
                e.preventDefault();
                return e.returnValue = 'Je hebt niet-opgeslagen wijzigingen. Weet je zeker dat je wilt vertrekken?';
            }
        });
        
        // Check internet status periodically
        setInterval(() => {
            if (navigator.onLine !== !noInternet) {
                location.reload();
            }
        }, 5000);
    </script>
</body>
</html>