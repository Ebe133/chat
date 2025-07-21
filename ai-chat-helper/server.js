// ========== CONFIGURATIE ========== //
const CONFIG = {
    SCREENSHOT_DELAY: 5000, // 5 seconden wachten
    OCR_LANGUAGE: 'nld+eng', // Nederlands + Engels
    AI_MODEL: 'gpt-3.5-turbo'
};

// ========== HOOFDFUNCTIES ========== //

// 1. Scherm vastleggen met 5 seconden delay
async function captureScreenWithDelay() {
    const btn = document.getElementById('captureBtn');
    btn.disabled = true;
    btn.textContent = `Scannen over ${CONFIG.SCREENSHOT_DELAY/1000} sec...`;
    
    try {
        // Wacht de ingestelde tijd
        await new Promise(resolve => setTimeout(resolve, CONFIG.SCREENSHOT_DELAY));
        
        // Maak screenshot (gebruik html2canvas)
        const canvas = await html2canvas(document.body);
        const screenshotData = canvas.toDataURL('image/png');
        
        // Herken tekst met OCR
        const text = await recognizeText(screenshotData);
        document.getElementById('scannedText').value = text;
        
        // Vraag AI om antwoord
        await getAIAnswer(text);
        
    } catch (error) {
        showError(`Fout: ${error.message}`);
    } finally {
        btn.disabled = false;
        btn.textContent = "📸 Scherm Vastleggen";
    }
}

// 2. Tekstherkenning met Tesseract.js
async function recognizeText(imageData) {
    showStatus("Tekst aan het herkennen...");
    
    const { data: { text } } = await Tesseract.recognize(
        imageData,
        CONFIG.OCR_LANGUAGE,
        { logger: progress => updateOCRProgress(progress) }
    );
    
    return text.trim() || "Geen tekst herkend";
}

// 3. AI-antwoord ophalen
async function getAIAnswer(question) {
    showStatus("Antwoord genereren...");
    
    try {
        const subject = document.getElementById('subject').value;
        const prompt = `Beantwoord deze ${subject}-vraag als expert:\n\n${question}`;
        
        const response = await fetchAIResponse(prompt);
        document.getElementById('answer').innerHTML = formatAnswer(response);
        
    } catch (error) {
        showError(`AI-fout: ${error.message}`);
    }
}

// ========== HULPFUNCTIES ========== //

async function fetchAIResponse(prompt) {
    // LOKALE TEST (verwijder dit voor echte API)
    if (window.location.href.includes('file://')) {
        return "⚠️ Demo-modus: Voeg een OpenAI API-sleutel toe in server.php voor echte antwoorden.\n\nVoorbeeldantwoord voor '" + prompt.substring(0, 30) + "...'";
    }

    const response = await fetch('server.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question: prompt })
    });
    
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    
    const data = await response.json();
    return data.reply || "Geen antwoord ontvangen";
}

function formatAnswer(text) {
    // Maak nieuwe regels zichtbaar
    return text.replace(/\n/g, '<br>')
               .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
}

function showStatus(message) {
    document.getElementById('answer').innerHTML = 
        `<div class="status">${message}</div>`;
}

function showError(message) {
    document.getElementById('answer').innerHTML = 
        `<div class="error">${message}</div>`;
    console.error(message);
}

function updateOCRProgress(progress) {
    if (progress.status === 'recognizing text') {
        const percent = Math.round(progress.progress * 100);
        showStatus(`OCR bezig: ${percent}%...`);
    }
}

// ========== INITIALISATIE ========== //
document.addEventListener('DOMContentLoaded', () => {
    // Voeg event listeners toe
    document.getElementById('captureBtn').addEventListener('click', captureScreenWithDelay);
    
    // Laad benodigde libraries dynamisch
    if (typeof html2canvas === 'undefined') {
        loadScript('https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
    }
    if (typeof Tesseract === 'undefined') {
        loadScript('https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js');
    }
});

function loadScript(url) {
    const script = document.createElement('script');
    script.src = url;
    document.head.appendChild(script);
}