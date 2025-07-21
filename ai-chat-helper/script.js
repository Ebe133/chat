document.getElementById('captureBtn').addEventListener('click', async () => {
    const btn = document.getElementById('captureBtn');
    btn.disabled = true;
    btn.textContent = "Scannen... (5 sec)";
    
    // Wacht 5 seconden voor screenshot
    setTimeout(async () => {
        try {
            // Maak screenshot van hele pagina
            const canvas = await html2canvas(document.documentElement);
            
            // OCR met Tesseract.js
            const { data: { text } } = await Tesseract.recognize(
                canvas.toDataURL(),
                'nld+eng',
                { logger: m => console.log(m) }
            );
            
            document.getElementById('scannedText').value = text;
            await getAIAnswer(text);
        } catch (error) {
            alert("Fout bij scannen: " + error.message);
        } finally {
            btn.disabled = false;
            btn.textContent = "📸 Scherm Vastleggen";
        }
    }, 5000); // 5 seconden wachten
});

async function getAIAnswer(question) {
    const answerBox = document.getElementById('aiAnswer');
    answerBox.innerHTML = '<div class="loading">Antwoord wordt gegenereerd...</div>';
    
    const subject = document.getElementById('subject').value;
    const prompt = `Je bent een ${subject} leraar. Beantwoord deze vraag duidelijk en educatief:\n\n${question}`;
    
    try {
        const response = await fetch('server.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: prompt })
        });
        
        const data = await response.json();
        if (data.status === 'success') {
            answerBox.innerHTML = data.reply;
        } else {
            throw new Error(data.message || 'Onbekende fout');
        }
    } catch (error) {
        answerBox.innerHTML = `<div class="error">Fout: ${error.message}</div>`;
    }
}