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

// Functie om internetconnectie te checken
function isInternetConnected() {
    $connected = @fsockopen("www.google.com", 80, $errno, $errstr, 2); 
    if ($connected) {
        fclose($connected);
        return true;
    }
    return false;
}

// Maak upload directory aan als deze niet bestaat
if (!file_exists($UPLOAD_DIR)) {
    if (!mkdir($UPLOAD_DIR, 0755, true)) {
        die("Kon upload directory niet aanmaken");
    }
}

// Laad .env bestand
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

// Verwerk geüpload bestand
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

// Roep OpenAI API aan
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
            'content' => 'Je bent een AI-huiswerkhulp voor Nederlandse studenten. Geef duidelijke, gestructureerde antwoorden in begrijpelijk Nederlands. Gebruik **vetgedrukt** voor belangrijke informatie. Voor rekenvragen: geef eerst het antwoord in **vet**, gevolgd door stap-voor-stap uitleg.'
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
            $textContent = extractTextFromPDF($file['path']);
            $content[] = [
                'type' => 'text',
                'text' => "Inhoud van PDF " . $file['name'] . ":\n" . $textContent
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

// Extraheer tekst uit PDF
function extractTextFromPDF($filepath) {
    if (!isInternetConnected()) {
        return "PDF-inhoud kan niet worden geëxtraheerd zonder internetverbinding";
    }
    
    if (!file_exists($filepath)) {
        return "PDF bestand niet gevonden";
    }
    
    $content = shell_exec('pdftotext ' . escapeshellarg($filepath) . ' - 2>&1');
    return $content ?: "Kon PDF-inhoud niet extraheren";
}

// Verwerk POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['status' => 'error', 'message' => 'Onbekende fout'];
    try {
        if (!isset($_POST['action'])) {
            throw new Exception("Geen actie gespecificeerd");
        }
        
        switch ($_POST['action']) {
            case 'send':
                $message = trim($_POST['message'] ?? '');
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
                
                if (empty($message) && empty($files)) {
                    throw new Exception("Voer een bericht in of selecteer een bestand");
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
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Huiswerkhulp - Slimme Studieassistent</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #e6f2ff;
            --accent: #4cc9f0;
            --success: #2ecc71;
            --warning: #f39c12;
            --error: #e74c3c;
            --light: #ffffff;
            --light-gray: #f8f9fa;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
            --dark: #212529;
            --text-primary: #212529;
            --text-secondary: #495057;
            --text-on-primary: #ffffff;
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --shadow-md: 0 6px 16px rgba(0,0,0,0.15);
            --transition: all 0.3s ease;
        }

        .dark-mode {
            --primary: #5d7bd4;
            --primary-dark: #4a6bc8;
            --primary-light: #1e293b;
            --accent: #6fd4f8;
            --light: #1a1a2e;
            --light-gray: #16213e;
            --medium-gray: #0f3460;
            --dark-gray: #a6a6a6;
            --dark: #e6e6e6;
            --text-primary: #e6e6e6;
            --text-secondary: #b8b8b8;
            --success: #48e68b;
            --error: #ff6b5b;
            --shadow: 0 4px 12px rgba(0,0,0,0.3);
            --shadow-md: 0 6px 16px rgba(0,0,0,0.4);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', 'Roboto', 'Helvetica Neue', sans-serif;
            background-color: var(--light-gray);
            color: var(--text-primary);
            line-height: 1.6;
            padding: 20px;
            transition: var(--transition);
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: var(--light);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
        }

        header {
            background: var(--primary);
            color: var(--text-on-primary);
            padding: 20px;
            text-align: center;
            position: relative;
        }

        h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .subtitle {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 400;
        }

        .status-banner {
            padding: 12px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 15px;
            border-radius: var(--border-radius-sm);
        }

        .offline-banner {
            background-color: rgba(231, 76, 60, 0.2);
            border-left: 4px solid var(--error);
            color: var(--error);
        }

        .online-banner {
            background-color: rgba(46, 204, 113, 0.2);
            border-left: 4px solid var(--success);
            color: var(--success);
        }

        .onboarding-tips {
            background: var(--primary-light);
            padding: 20px;
            margin-bottom: 15px;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }

        .onboarding-tips h3 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }

        .quick-action-btn {
            background: var(--light);
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
        }

        .quick-action-btn:hover {
            background: var(--primary);
            color: var(--text-on-primary);
        }

        .chat-container {
            height: 60vh;
            overflow-y: auto;
            padding: 20px;
            background: var(--light);
            scroll-behavior: smooth;
            transition: var(--transition);
        }

        .message {
            margin-bottom: 15px;
            padding: 12px 16px;
            border-radius: var(--border-radius);
            max-width: 85%;
            line-height: 1.5;
            position: relative;
            animation: fadeIn 0.3s ease-out;
            transition: var(--transition);
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
            background: var(--light-gray);
            margin-right: auto;
            border-bottom-left-radius: 4px;
            box-shadow: var(--shadow);
        }

        .message-time {
            font-size: 0.75rem;
            opacity: 0.7;
            margin-top: 5px;
            display: block;
        }

        .message-content {
            word-wrap: break-word;
        }

        .message-content img, .message-content embed {
            max-width: 100%;
            max-height: 300px;
            border-radius: var(--border-radius-sm);
            margin-top: 10px;
            display: block;
            box-shadow: var(--shadow);
        }

        .input-area {
            display: flex;
            gap: 10px;
            padding: 15px;
            background: var(--light);
            flex-wrap: wrap;
            transition: var(--transition);
        }

        .input-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 200px;
        }

        textarea {
            flex: 1;
            padding: 12px;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 16px;
            resize: none;
            min-height: 60px;
            max-height: 150px;
            transition: var(--transition);
            background: var(--light);
            color: var(--text-primary);
        }

        textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);
            outline: none;
        }

        button {
            padding: 12px 20px;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .primary-btn {
            background: var(--primary);
            color: var(--text-on-primary);
        }

        .primary-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .primary-btn:disabled {
            background: var(--dark-gray);
            transform: none;
            box-shadow: none;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .secondary-btn {
            background: var(--light);
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .secondary-btn:hover {
            background: var(--primary-light);
        }

        .icon-btn {
            padding: 12px;
            min-width: 44px;
            height: 44px;
            border-radius: 50%;
        }

        #imageUpload {
            display: none;
        }

        .upload-btn {
            background: var(--light);
            color: var(--primary);
            border-radius: var(--border-radius);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 600;
            border: 1px solid var(--primary);
            padding: 12px 16px;
        }

        .upload-btn:hover {
            background: var(--primary-light);
        }

        .preview-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .file-preview {
            position: relative;
            width: 80px;
            height: 80px;
            border-radius: var(--border-radius-sm);
            border: 1px solid var(--medium-gray);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--light-gray);
        }

        .file-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .file-preview .file-icon {
            font-size: 24px;
            color: var(--dark-gray);
        }

        .file-preview .remove-btn {
            position: absolute;
            top: 2px;
            right: 2px;
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
            opacity: 0;
            transition: var(--transition);
        }

        .file-preview:hover .remove-btn {
            opacity: 1;
        }

        .progress-container {
            width: 100%;
            background: var(--medium-gray);
            border-radius: 4px;
            margin-top: 5px;
            overflow: hidden;
        }

        .progress-bar {
            height: 6px;
            background: var(--primary);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .typing-indicator {
            display: inline-flex;
            gap: 6px;
            padding: 12px 16px;
            background: var(--light-gray);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 12px;
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
            opacity: 0.6;
            animation: bounce 1.4s infinite ease-in-out;
        }

        @keyframes bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.6; }
            30% { transform: translateY(-4px); opacity: 1; }
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--light);
            padding: 15px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            max-width: 300px;
            z-index: 1000;
            border-left: 4px solid var(--error);
            animation: slideIn 0.3s ease-out;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .toast.success {
            border-left-color: var(--success);
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toast p {
            margin-bottom: 0;
        }

        .toast-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }

        .toast-btn {
            padding: 6px 12px;
            font-size: 14px;
            border-radius: var(--border-radius-sm);
        }

        .toast-btn.primary {
            background: var(--primary);
            color: white;
        }

        .toast-btn.secondary {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .floating-btn {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow-md);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .floating-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            flex-direction: column;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid var(--medium-gray);
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .loading-text {
            color: white;
            margin-top: 20px;
            font-size: 18px;
        }

        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltip-text {
            visibility: hidden;
            width: 120px;
            background-color: var(--dark);
            color: var(--light);
            text-align: center;
            border-radius: var(--border-radius-sm);
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.8rem;
        }

        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .container {
                border-radius: 0;
            }

            header {
                padding: 15px;
            }

            h1 {
                font-size: 1.5rem;
            }

            .subtitle {
                font-size: 0.9rem;
            }

            .chat-container {
                height: 65vh;
                padding: 15px;
            }

            .message {
                max-width: 90%;
                padding: 10px 12px;
                font-size: 15px;
            }

            .input-area {
                flex-direction: column;
                padding: 10px;
            }

            .quick-actions {
                justify-content: center;
            }

            .floating-btn {
                width: 40px;
                height: 40px;
                font-size: 18px;
                bottom: 10px;
                left: 10px;
            }
        }

        @media (max-width: 480px) {
            .message {
                max-width: 95%;
            }

            .quick-action-btn {
                font-size: 0.8rem;
                padding: 6px 10px;
            }

            button {
                padding: 10px 15px;
                font-size: 14px;
            }
        }


        .action-btn {
    padding: 12px 16px;
    background: var(--light);
    color: var(--primary);
    border: 1px solid var(--primary);
    border-radius: var(--border-radius);
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
}

.action-btn:hover {
    background: var(--primary-light);
}

.action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.action-btn i {
    font-size: 1rem;
}

#quizBtn {
    background: var(--success);
    color: white;
    border-color: var(--success);
}

#quizBtn:hover {
    background: #27ae60;
}

#scanBtn {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
}

#scanBtn:hover {
    background: #3aa8d8;
}
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1><i class="fas fa-robot"></i> AI Huiswerkhulp</h1>
            <p class="subtitle">Jouw slimme studieassistent</p>
        </header>

        <?php if ($noInternet): ?>
            <div class="status-banner offline-banner">
                <i class="fas fa-wifi-slash"></i> Geen internetverbinding - beperkte functionaliteit
            </div>
        <?php else: ?>
            <div class="status-banner online-banner">
                <i class="fas fa-wifi"></i> Verbonden met AI-service
            </div>
        <?php endif; ?>

        <?php if (empty($_SESSION['chat_history'])): ?>
            <div class="onboarding-tips">
                <h3><i class="fas fa-lightbulb"></i> Hoe kan ik je helpen?</h3>
                <p>Stel je vraag of upload je huiswerk voor persoonlijke uitleg:</p>
                
                <div class="quick-actions">
                    <button class="quick-action-btn" onclick="insertExample('Leg uit hoe fotosynthese werkt')">
                        <i class="fas fa-leaf"></i> Biologie
                    </button>
                    <button class="quick-action-btn" onclick="insertExample('Hoe bereken ik de stelling van Pythagoras?')">
                        <i class="fas fa-square-root-alt"></i> Wiskunde
                    </button>
                    <button class="quick-action-btn" onclick="insertExample('Wat was de aanleiding voor WO2?')">
                        <i class="fas fa-landmark"></i> Geschiedenis
                    </button>
                    <button class="quick-action-btn" onclick="insertExample('Geef een samenvatting van Max Havelaar')">
                        <i class="fas fa-book"></i> Nederlands
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <div class="chat-container" id="chatContainer">
            <?php if (!empty($_SESSION['chat_history'])): ?>
                <?php foreach ($_SESSION['chat_history'] as $msg): ?>
                    <?php if ($msg['role'] !== 'system'): ?>
                        <div class="message <?= $msg['role'] === 'user' ? 'user-message' : 'bot-message' ?>">
                            <div class="message-content">
                                <?php if (is_array($msg['content'])): ?>
                                    <?php foreach ($msg['content'] as $content): ?>
                                        <?php if ($content['type'] === 'text'): ?>
                                            <?= formatMessageContent($content['text']) ?>
                                        <?php elseif (isset($content['image_url'])): ?>
                                            <img src="<?= htmlspecialchars($content['image_url']['url']) ?>" alt="Uploaded image">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?= formatMessageContent($msg['content']) ?>
                                <?php endif; ?>
                            </div>
                            <small class="message-time">
                                <?= $msg['role'] === 'user' ? 'Jij' : 'AI Assistent' ?> • 
                                <?= date('H:i') ?>
                            </small>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="message bot-message">
                    <div class="message-content">
                        Hallo! Ik ben je AI huiswerkassistent. Stel me gerust een vraag of upload je opdracht.
                        <br><br>
                        <strong>Voorbeelden:</strong>
                        <ul>
                            <li>"Leg uit hoe fotosynthese werkt"</li>
                            <li>"Hoe los ik deze wiskunde opgave op?"</li>
                            <li>"Wat is de hoofdstad van Frankrijk?"</li>
                        </ul>
                    </div>
                    <small class="message-time">AI Assistent • <?= date('H:i') ?></small>
                </div>
            <?php endif; ?>
        </div>

        <div class="input-area">
            <div class="input-group">
                <textarea id="userInput" placeholder="Typ je vraag hier..." aria-label="Typ je vraag" 
                    <?= $noInternet ? 'disabled placeholder="Geen internetverbinding"' : '' ?>></textarea>
                <div class="preview-container" id="filePreviews"></div>
                <div id="uploadProgress" class="progress-container">
                    <div class="progress-bar" style="width: 0%"></div>
                </div>
            </div>
            <div class="input-area">
    <!-- Bestaande elementen blijven staan -->
    
    <!-- Scan knop -->
    <button id="scanBtn" class="action-btn" aria-label="Schermopname maken" <?= $noInternet ? 'disabled' : '' ?>>
        <i class="fas fa-camera"></i> Scan
    </button>
    
    <!-- Oefentoets knop -->
    <a href="oefentoets.php" id="quizBtn" class="action-btn" aria-label="Oefentoets maken" <?= $noInternet ? 'disabled' : '' ?>>
        <i class="fas fa-clipboard-list"></i> Oefentoets
    </a>
    
    <!-- Bestaande knoppen blijven staan -->
</div>

            <label class="upload-btn" aria-label="Bestand uploaden" 
                <?= $noInternet ? 'style="opacity:0.5; pointer-events:none;"' : '' ?>>
                <i class="fas fa-paperclip"></i>
                <input type="file" id="imageUpload" accept="image/*, .pdf" multiple 
                    <?= $noInternet ? 'disabled' : '' ?>>
            </label>

            <button id="sendBtn" class="primary-btn" aria-label="Verstuur vraag" 
                <?= $noInternet ? 'disabled' : '' ?>>
                <i class="fas fa-paper-plane"></i> Versturen
            </button>

            <button id="clearBtn" class="secondary-btn" aria-label="Wis conversatie">
                <i class="fas fa-trash-alt"></i> Wissen
            </button>
        </div>
    </div>

    <button id="darkModeToggle" class="floating-btn" aria-label="Dark mode toggle">
        <i class="fas fa-moon"></i>
    </button>

    <script>
        // Helper functies
        function formatMessageContent(text) {
            // Vervang **vet** met <strong>vet</strong>
            let formatted = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            // Vervang nieuwe regels met <br>
            formatted = formatted.replace(/\n/g, '<br>');
            // Vervang lijsten met bullets
            formatted = formatted.replace(/- (.*?)(<br>|$)/g, '<li>$1</li>');
            // Vervang genummerde lijsten
            formatted = formatted.replace(/(\d+)\. (.*?)(<br>|$)/g, '<li>$2</li>');
            // Voeg <ul> toe rond lijsten
            formatted = formatted.replace(/(<li>.*?<\/li>)+/g, '<ul>$&</ul>');
            return formatted;
        }

        function showToast(message, type = 'error', duration = 5000) {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <p>${message}</p>
                <div class="toast-actions">
                    <button class="toast-btn primary" onclick="this.parentElement.parentElement.remove()">OK</button>
                </div>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideIn 0.3s reverse forwards';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

        function showLoading(message = 'Even geduld...') {
            const overlay = document.createElement('div');
            overlay.className = 'loading-overlay';
            overlay.innerHTML = `
                <div class="loading-spinner"></div>
                <div class="loading-text">${message}</div>
            `;
            document.body.appendChild(overlay);
            return overlay;
        }

        function hideLoading(overlay) {
            if (overlay && overlay.parentNode) {
                overlay.remove();
            }
        }

        function insertExample(text) {
            const input = document.getElementById('userInput');
            input.value = text;
            input.focus();
        }

        // DOM elementen
        const chatContainer = document.getElementById('chatContainer');
        const userInput = document.getElementById('userInput');
        const sendBtn = document.getElementById('sendBtn');
        const clearBtn = document.getElementById('clearBtn');
        const imageUpload = document.getElementById('imageUpload');
        const filePreviews = document.getElementById('filePreviews');
        const uploadProgress = document.getElementById('uploadProgress');
        const darkModeToggle = document.getElementById('darkModeToggle');
        
        // State variabelen
        let uploadedFiles = [];
        let darkMode = localStorage.getItem('darkMode') === 'true';
        const noInternet = <?= $noInternet ? 'true' : 'false' ?>;

        // Dark mode functionaliteit
        function applyDarkMode() {
            if (darkMode) {
                document.body.classList.add('dark-mode');
                darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                document.body.classList.remove('dark-mode');
                darkModeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            }
        }

        function toggleDarkMode() {
            darkMode = !darkMode;
            localStorage.setItem('darkMode', darkMode);
            applyDarkMode();
            
            // Toon feedback aan gebruiker
            const message = darkMode ? 'Donker thema geactiveerd' : 'Licht thema geactiveerd';
            showToast(message, 'success', 2000);
        }

        // Bericht weergeven in chat
        function addMessage(content, sender, isFile = false) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${sender}-message`;
            
            let contentHTML = '';
            if (isFile) {
                if (content.type.startsWith('image/')) {
                    contentHTML = `<img src="${content.url}" alt="Geüpload bestand">`;
                } else {
                    contentHTML = `<div class="file-icon"><i class="fas fa-file-pdf"></i> ${content.name}</div>`;
                }
            } else {
                contentHTML = formatMessageContent(content);
            }
            
            messageDiv.innerHTML = `
                <div class="message-content">${contentHTML}</div>
                <small class="message-time">${sender === 'user' ? 'Jij' : 'AI Assistent'} • ${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</small>
            `;
            
            chatContainer.appendChild(messageDiv);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        // Typ indicator weergeven
        function showTypingIndicator() {
            const typingDiv = document.createElement('div');
            typingDiv.className = 'message bot-message typing-indicator';
            typingDiv.id = 'typingIndicator';
            typingDiv.innerHTML = `
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            `;
            chatContainer.appendChild(typingDiv);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        function hideTypingIndicator() {
            const typing = document.getElementById('typingIndicator');
            if (typing) typing.remove();
        }

        // Bestanden verwerken
        function handleFileUpload(files) {
            filePreviews.innerHTML = '';
            uploadProgress.querySelector('.progress-bar').style.width = '0%';
            uploadedFiles = [];
            
            for (let file of files) {
                if (!file.type.match('image.*|application/pdf')) continue;
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewDiv = document.createElement('div');
                    previewDiv.className = 'file-preview';
                    
                    if (file.type.startsWith('image/')) {
                        previewDiv.innerHTML = `
                            <img src="${e.target.result}" alt="${file.name}">
                            <div class="remove-btn" onclick="removeFile(this)">&times;</div>
                        `;
                    } else {
                        previewDiv.innerHTML = `
                            <div class="file-icon"><i class="fas fa-file-pdf"></i></div>
                            <div class="remove-btn" onclick="removeFile(this)">&times;</div>
                        `;
                    }
                    
                    filePreviews.appendChild(previewDiv);
                    uploadedFiles.push(file);
                    
                    // Simuleer upload progress
                    let progress = 0;
                    const interval = setInterval(() => {
                        progress += 10;
                        uploadProgress.querySelector('.progress-bar').style.width = `${progress}%`;
                        if (progress >= 100) clearInterval(interval);
                    }, 100);
                };
                
                reader.readAsDataURL(file);
            }
        }

        function removeFile(element) {
            const previewDiv = element.parentElement;
            const index = Array.from(filePreviews.children).indexOf(previewDiv);
            
            if (index !== -1) {
                uploadedFiles.splice(index, 1);
                previewDiv.remove();
                
                if (uploadedFiles.length === 0) {
                    uploadProgress.querySelector('.progress-bar').style.width = '0%';
                }
            }
        }

        // Bericht verzenden naar server
        async function sendMessage() {
            const message = userInput.value.trim();
            
            if (!message && uploadedFiles.length === 0) {
                showToast('Voer een bericht in of selecteer een bestand');
                return;
            }
            
            // Toon bericht in chat
            if (message) {
                addMessage(message, 'user');
            }
            
            // Toon bestanden in chat
            for (let file of uploadedFiles) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    addMessage({
                        type: file.type,
                        url: e.target.result,
                        name: file.name
                    }, 'user', true);
                };
                reader.readAsDataURL(file);
            }
            
            // Reset input
            userInput.value = '';
            filePreviews.innerHTML = '';
            uploadProgress.querySelector('.progress-bar').style.width = '0%';
            
            // Toon typing indicator
            showTypingIndicator();
            
            // Disable send button tijdens verwerken
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verwerken...';
            
            try {
                const formData = new FormData();
                formData.append('action', 'send');
                if (message) formData.append('message', message);
                
                for (let file of uploadedFiles) {
                    formData.append('files[]', file);
                }
                
                const response = await fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.status !== 'success') {
                    throw new Error(data.message || 'Er is een fout opgetreden');
                }
                
                if (data.reply) {
                    addMessage(data.reply, 'bot');
                }
            } catch (error) {
                console.error('Fout:', error);
                showToast(getFriendlyError(error.message));
            } finally {
                hideTypingIndicator();
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Versturen';
                uploadedFiles = [];
            }
        }

        // Geschiedenis wissen
        async function clearHistory() {
            const loadingOverlay = showLoading('Conversatie wordt gewist...');
            
            try {
                const response = await fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=clear'
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    chatContainer.innerHTML = `
                        <div class="message bot-message">
                            <div class="message-content">
                                Hallo! Ik ben je AI huiswerkassistent. Stel me gerust een vraag of upload je opdracht.
                                <br><br>
                                <strong>Voorbeelden:</strong>
                                <ul>
                                    <li>"Leg uit hoe fotosynthese werkt"</li>
                                    <li>"Hoe los ik deze wiskunde opgave op?"</li>
                                    <li>"Wat is de hoofdstad van Frankrijk?"</li>
                                </ul>
                            </div>
                            <small class="message-time">AI Assistent • ${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</small>
                        </div>
                    `;
                    
                    // Voeg onboarding tips weer toe
                    const onboardingTips = document.createElement('div');
                    onboardingTips.className = 'onboarding-tips';
                    onboardingTips.innerHTML = `
                        <h3><i class="fas fa-lightbulb"></i> Hoe kan ik je helpen?</h3>
                        <p>Stel je vraag of upload je huiswerk voor persoonlijke uitleg:</p>
                        
                        <div class="quick-actions">
                            <button class="quick-action-btn" onclick="insertExample('Leg uit hoe fotosynthese werkt')">
                                <i class="fas fa-leaf"></i> Biologie
                            </button>
                            <button class="quick-action-btn" onclick="insertExample('Hoe bereken ik de stelling van Pythagoras?')">
                                <i class="fas fa-square-root-alt"></i> Wiskunde
                            </button>
                            <button class="quick-action-btn" onclick="insertExample('Wat was de aanleiding voor WO2?')">
                                <i class="fas fa-landmark"></i> Geschiedenis
                            </button>
                            <button class="quick-action-btn" onclick="insertExample('Geef een samenvatting van Max Havelaar')">
                                <i class="fas fa-book"></i> Nederlands
                            </button>
                        </div>
                    `;
                    
                    const container = document.querySelector('.container');
                    const chatContainer = document.getElementById('chatContainer');
                    container.insertBefore(onboardingTips, chatContainer);
                    
                    showToast('Conversatie gewist', 'success', 2000);
                }
            } catch (error) {
                console.error('Fout bij wissen:', error);
                showToast('Kon conversatie niet wissen: ' + error.message);
            } finally {
                hideLoading(loadingOverlay);
            }
        }

        // Vriendelijke foutmeldingen
        function getFriendlyError(error) {
            const errorMap = {
                'internet': 'Geen internetverbinding - kan geen AI-antwoord genereren',
                'size': 'Het bestand is te groot (maximaal 5MB toegestaan)',
                'type': 'Alleen afbeeldingen (JPEG, PNG, GIF) en PDF-bestanden zijn toegestaan',
                'API': 'De AI-service is tijdelijk niet beschikbaar. Probeer het later opnieuw.',
                'empty': 'Voer een bericht in of selecteer een bestand',
                'default': 'Er is een fout opgetreden. Probeer het opnieuw.'
            };
            
            if (error.includes('internet')) return errorMap.internet;
            if (error.includes('size')) return errorMap.size;
            if (error.includes('type')) return errorMap.type;
            if (error.includes('API')) return errorMap.API;
            if (error.includes('empty')) return errorMap.empty;
            return errorMap.default;
        }

        // Event listeners
        darkModeToggle.addEventListener('click', toggleDarkMode);
        sendBtn.addEventListener('click', sendMessage);
        clearBtn.addEventListener('click', clearHistory);
        
        userInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
            
            // Auto-expand textarea
            if (e.target.scrollHeight > e.target.clientHeight) {
                e.target.style.height = e.target.scrollHeight + 'px';
            }
        });
        
        imageUpload.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFileUpload(e.target.files);
            }
        });

        // Initialisatie
        applyDarkMode();
        
        // Focus op inputveld bij laden
        if (!noInternet) {
            userInput.focus();
        }
        
        // Voeg globale functies toe aan window
        window.insertExample = insertExample;
        window.removeFile = removeFile;
        
    </script>
</body>
</html>