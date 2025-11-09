<?php
// ===================================================================
// CHATBOT WIDGET - VYSKAKOVACÍ OKNO VE STYLU AI4NGO
// (FINÁLNÍ OPRAVENÁ VERZE - POUZE WIDGET)
// ===================================================================

// Nastavení CSP hlaviček pro povolení externích zdrojů
header("Content-Security-Policy: default-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://fonts.gstatic.com https://api.anthropic.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://static.cloudflareinsights.com;");

// Spuštění session pro správu kontextu
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===================================================================
// ČÁST 1: DEFINICE VŠECH PHP FUNKCÍ
// ===================================================================

// ========= SAMOUČÍCÍ FUNKCE =========

function loadLearnedData() {
    $learnedFile = 'learned_data.json';
    if (file_exists($learnedFile)) {
        $data = json_decode(file_get_contents($learnedFile), true);
        if (is_array($data)) {
            return $data;
        }
    }
    
    return [
        'interactions' => [],
        'successful_responses' => [],
        'common_questions' => [],
        'last_updated' => date('c')
    ];
}

function saveLearnedData($data) {
    $learnedFile = 'learned_data.json';
    
    if (count($data['interactions']) > 1000) {
        $data['interactions'] = array_slice($data['interactions'], -1000);
    }
    
    $data['last_updated'] = date('c');
    
    file_put_contents($learnedFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function saveInteractionForLearning($question, $answer, $context) {
    $badAnswerSubstrings = [
        "API endpoint nebyl nalezen", "Omlouvám se, momentálně nemohu odpovědět", "Chyba:",
        "není nastaven", "neplatný formát", "Dočasně nedostupné",
        "na tento dotaz nemohu odpovědět", "zatím neznám odpověď", "není správně nakonfigurován"
    ];
    
    if (empty(trim($answer))) {
        error_log("Skipping learning: Answer is empty.");
        return;
    }
    
    foreach ($badAnswerSubstrings as $substring) {
        if (mb_stripos($answer, $substring, 0, 'UTF-8') !== false) {
            error_log("Skipping learning: Answer contains bad substring '{$substring}'");
            return;
        }
    }
    
    if (mb_strlen(trim($answer), 'UTF-8') < 20) {
        error_log("Skipping learning: Answer is too short (length: " . mb_strlen(trim($answer), 'UTF-8') . ")");
        return;
    }

    $learnedData = loadLearnedData();
    $interaction = [
        'question' => $question,
        'answer' => $answer,
        'context_used' => $context,
        'timestamp' => time(),
        'usage_count' => 1
    ];  
    
    $similarFound = false;
    foreach ($learnedData['interactions'] as &$existing) {
        $similarity = calculateQuestionSimilarity($question, $existing['question'] ?? '');
        if ($similarity > 0.7) {
            $existing['usage_count']++;
            $existing['last_used'] = time();
            $similarFound = true;
            break;
        }
    }
    
    if (!$similarFound) {
        $learnedData['interactions'][] = $interaction;
    }
    
    updateCommonQuestions($learnedData, $question);
    
    saveLearnedData($learnedData);
}

function getLearnedResponse($question, $learnedData) {
    if (empty($learnedData['interactions'])) {
        return null;
    }
    
    $bestMatch = null;
    $bestScore = 0;
    
    foreach ($learnedData['interactions'] as $interaction) {
        $similarity = calculateQuestionSimilarity($question, $interaction['question'] ?? '');
        $score = $similarity * (1 + log(1 + ($interaction['usage_count'] ?? 1)));
        
        if ($score > $bestScore && $score > 0.6) {
            $bestScore = $score;
            $bestMatch = $interaction['answer'] ?? '';
        }
    }
    
    return $bestMatch;
}

function calculateQuestionSimilarity($q1, $q2) {
    if (empty($q1) || empty($q2)) return 0;
    
    $q1 = strtolower($q1);
    $q2 = strtolower($q2);
    
    $words1 = array_unique(explode(' ', $q1));
    $words2 = array_unique(explode(' ', $q2));
    
    $commonWords = array_intersect($words1, $words2);
    $totalWords = count(array_merge($words1, $words2));
    
    if ($totalWords === 0) return 0;
    
    return (2 * count($commonWords)) / $totalWords;
}

function updateCommonQuestions(&$learnedData, $newQuestion) {
    $found = false;
    
    foreach ($learnedData['common_questions'] as &$item) {
        if (calculateQuestionSimilarity($item['question'] ?? '', $newQuestion) > 0.8) {
            $item['frequency']++;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $learnedData['common_questions'][] = [
            'question' => $newQuestion,
            'frequency' => 1,
            'first_seen' => time()
        ];
    }
    
    usort($learnedData['common_questions'], function($a, $b) {
        return ($b['frequency'] ?? 0) - ($a['frequency'] ?? 0);
    });
    
    $learnedData['common_questions'] = array_slice($learnedData['common_questions'], 0, 20);
}

// ========= POMOCNÉ FUNKCE (KONTEXT, KLÍČOVÁ SLOVA) =========

function buildKnowledgeBase($articles, $glossary, $products, $relevantContext = "", $learnedData = []) {
    $context = "Zde jsou informace, ze kterých můžeš čerpat:\n\n";
    
    if (!empty($relevantContext)) {
        $context .= "== NEJRELEVANTNĚJŠÍ OBSAH ==\n" . $relevantContext . "\n";
    }
    
    if (!empty($learnedData['interactions'])) {
        $context .= "== NAUČENÉ ODPOVĚDI Z PŘEDCHOZÍCH KONVERZACÍ ==\n";
        $recentInteractions = array_slice($learnedData['interactions'], -5);
        foreach ($recentInteractions as $interaction) {
            $context .= "OTÁZKA: " . ($interaction['question'] ?? '') . "\n";
            $context .= "ODPOVĚĎ: " . ($interaction['answer'] ?? '') . "\n\n";
        }
    }
    
    $context .= "== ČLÁNKY ==\n";
    foreach (array_slice($articles, 0, 10) as $item) { 
        $context .= "Název: " . ($item['title'] ?? '') . "\nObsah: " . ($item['perex'] ?? '') . "\nURL: " . ($item['url'] ?? '') . "\n\n"; 
    }
    
    $context .= "== SLOVNÍK POJMŮ ==\n";
    foreach (array_slice($glossary, 0, 15) as $item) { 
        $context .= "Pojem: " . ($item['term'] ?? '') . "\nDefinice: " . ($item['definition'] ?? '') . "\n\n"; 
    }
    
    $context .= "== PRODUKTY ==\n";
    foreach ($products as $item) { 
        $context .= "Název: " . ($item['title'] ?? '') . "\nPoužití: " . ($item['use_cases'] ?? '') . "\n\n"; 
    }
    
    return $context;
}

function findRelevantContext($userMessage, $articles, $glossary, $products) {
    $keywords = extractKeywords($userMessage);
    if (empty($keywords)) return "";
    
    $relevantContent = "";
    
    foreach ($articles as $article) {
        $relevance = calculateRelevance($article, $keywords);
        if ($relevance > 0.2) {
            $relevantContent .= "ČLÁNEK: " . ($article['title'] ?? '') . "\n" . 
                              "OBSAH: " . ($article['perex'] ?? '') . "\n" .
                              "URL: " . ($article['url'] ?? '') . "\n\n";
        }
    }
    
    foreach ($glossary as $item) {
        $relevance = calculateRelevance($item, $keywords, 'term');
        if ($relevance > 0.3) {
            $relevantContent .= "POJEM: " . ($item['term'] ?? '') . "\n" .
                              "DEFINICE: " . ($item['definition'] ?? '') . "\n\n";
        }
    }
    
    foreach ($products as $item) {
        $relevance = calculateRelevance($item, $keywords);
        if ($relevance > 0.2) {
            $relevantContent .= "PRODUKT: " . ($item['title'] ?? '') . "\n" .
                              "POUŽITÍ: " . ($item['use_cases'] ?? '') . "\n\n";
        }
    }
    
    return $relevantContent;
}

function extractKeywords($text) {
    if (empty($text)) return [];
    
    $stopwords = ['a', 'aby', 'ale', 'asi', 'az', 'bez', 'bude', 'by', 'byt', 'ci', 'co', 'cz', 'dalsi', 'dnes', 'do', 'ho', 'i', 'jako', 'je', 'jeho', 'jeji', 'jejich', 'jen', 'jenž', 'ji', 'jine', 'jiz', 'k', 'kam', 'kde', 'kdo', 'kdy', 'kdyz', 'ke', 'ktera', 'které', 'kteri', 'která', 'který', 'ku', 'ma', 'mate', 'me', 'melo', 'mi', 'mit', 'mne', 'mně', 'muj', 'muze', 'my', 'na', 'nad', 'nam', 'napiste', 'nas', 'naše', 'ne', 'nebo', 'necht', 'nejsou', 'neni', 'nez', 'ní', 'nové', 'o', 'od', 'on', 'pak', 'po', 'pod', 'podle', 'pouze', 'prave', 'pro', 'proc', 'proto', 'protoze', 'prvni', 'pta', 'při', 're', 's', 'se', 'si', 'sice', 'společnosti', 'svych', 'své', 'ta', 'tak', 'take', 'taky', 'te', 'tedy', 'tema', 'teprve', 'ti', 'to', 'tohle', 'toho', 'tohoto', 'tom', 'tomto', 'tomu', 'toto', 'tu', 'tuto', 'tvůj', 'ty', 'tyto', 'u', 'už', 'v', 'vam', 'vas', 'vase', 've', 'vedle', 'vice', 'vsak', 'vám', 'vás', 'z', 'za', 'zda', 'zde', 'ze', 'zpet', 'zpravy', 'i'];
    
    $words = preg_split('/\s+/', strtolower($text));
    $keywords = array_filter($words, function($word) use ($stopwords) {
        return strlen($word) > 2 && !in_array($word, $stopwords) && preg_match('/^[a-ž]+$/u', $word);
    });
    
    return array_values($keywords);
}

function calculateRelevance($item, $keywords, $field = 'title') {
    if (empty($keywords)) return 0;
    
    $text = '';
    if ($field === 'title') {
        $text = strtolower(($item['title'] ?? '') . ' ' . ($item['perex'] ?? '') . ' ' . ($item['use_cases'] ?? '') . ' ' . ($item['definition'] ?? ''));
    } else {
        $text = strtolower($item[$field] ?? '');
    }
    
    $score = 0;
    foreach ($keywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            $score += 1;
            if (strpos(strtolower($item['title'] ?? ''), $keyword) !== false) {
                $score += 2;
            }
        }
    }
    
    return $score / count($keywords);
}

// ========= HLAVNÍ PHP FUNKCE (LOGIKA, NLP) =========

/**
 * OPRAVA TC-06 (Soukromí): Přidán záměr 'delete_data'
 */
function detectUserIntent($message) {
    $messageLower = strtolower($message);

    $intents = [
        'greeting' => ['ahoj', 'dobrý den', 'čau', 'zdravím', 'cus', 'nazdar', 'hello', 'hi', 'dobré ráno', 'dobrý večer'],
        'farewell' => ['děkuji', 'díky', 'na shledanou', 'měj se', 'bye', 'konec', 'děkuji za pomoc', 'papa'],
        'help' => ['co umíš', 'pomoc', 'nápověda', 'funkce', 'pomůžeš', 'co dokážeš', 'pomoc s'],
        'product_info' => ['produkt', 'služba', 'nástroj', 'cena', 'koupit', 'zakoupit', 'k dispozici', 'nabídka'],
        'article_search' => ['článek', 'blog', 'návod', 'tutorial', 'příručka', 'dokumentace', 'najít článek', 'hledat článek'],
        'technical_question' => ['jak funguje', 'co je', 'vysvětli', 'definice', 'význam', 'popis', 'jak na to'],
        'complaint' => ['problém', 'chyba', 'nefunguje', 'špatně', 'nespokojen', 'selhalo', 'nejde', 'nefunguje'],
        'pricing' => ['cena', 'cenovka', 'kolik stojí', 'platba', 'zdarma', 'drahý', 'levný', 'ceník'],
        'delete_data' => ['vymaž', 'smaž', 'zapomeň', 'reset', 'delete', 'vymažte moji konverzaci', 'vymazat konverzaci'] // <-- OPRAVENO
    ];

    foreach ($intents as $intent => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($messageLower, $keyword) !== false) {
                return $intent;
            }
        }
    }

    return 'general_query';
}

function generateSmartSuggestions($context, $conversation) {
    $suggestions = [];

    switch ($context['user_intent']) {
        case 'product_info':
            $suggestions = ["Technické specifikace", "Ceník", "Zkušební verze", "Reference"];
            break;
        case 'article_search':
            $suggestions = ["Nejnovější články", "Nejčtenější", "Hledat podle tématu", "Odborné studie"];
            break;
        case 'technical_question':
            $suggestions = ["Příklady použití", "Dokumentace", "Video tutoriály", "Komunitní fórum"];
            break;
        default:
            $suggestions = ["Co je to AI?", "Jaké máte produkty?", "Hledám článek o marketingu"];
    }

    if (!empty($context['mentioned_products'])) {
        $lastProduct = end($context['mentioned_products']);
        array_unshift($suggestions, "Více o $lastProduct");
    }

    if ($context['conversation_depth'] > 3) {
        $suggestions[] = "Potřebuji lidskou podporu";
        $suggestions[] = "Stáhnout dokumentaci";
    }

    if (!empty($context['sentiment_history'])) {
        $lastSentiment = end($context['sentiment_history']);
        if ($lastSentiment['sentiment'] === 'negative') {
            array_unshift($suggestions, "Kontaktovat podporu", "Nahlásit problém");
        }
    }

    return array_slice(array_unique($suggestions), 0, 3);
}

function analyzeSentiment($message) {
    if (empty($message)) return 'neutral';

    $positiveWords = [
        'skvělý', 'super', 'děkuji', 'pomohlo', 'výborně', 'dobrý', 'perfektní',
        'úžasný', 'díky', 'pěkné', 'vynikající', 'spokojen', 'rád', 'báječný', 'skvěle'
    ];
    $negativeWords = [
        'problém', 'chyba', 'špatně', 'nefunguje', 'nespokojen', 'špatný',
        'hloupý', 'špatné', 'špatná', 'ne', 'špatně', 'katastrofa', 'hrozný', 'otřesný'
    ];
    $positivePatterns = [
        '/děkuji(\s+.*)?/ui', '/jsem\s+spokojen/ui', '/pomohl(o|a)/ui', '/výborně/ui', '/skvělé/ui'
    ];
    $negativePatterns = [
        '/nefunguje/ui', '/problém/ui', '/nespokojen/ui', '/chyba/ui', '/katastrofa/ui', '/nejde/ui'
    ];

    $positiveCount = 0;
    $negativeCount = 0;

    $words = explode(' ', strtolower($message));
    foreach ($words as $word) {
        $word = preg_replace('/[^\w]/u', '', $word);
        if (in_array($word, $positiveWords)) $positiveCount++;
        if (in_array($word, $negativeWords)) $negativeCount++;
    }

    foreach ($positivePatterns as $pattern) {
        if (preg_match($pattern, $message)) $positiveCount += 2;
    }
    foreach ($negativePatterns as $pattern) {
        if (preg_match($pattern, $message)) $negativeCount += 2;
    }

    if ($positiveCount > $negativeCount + 1) return 'positive';
    if ($negativeCount > $positiveCount + 1) return 'negative';
    return 'neutral';
}

function detectConversationTopic($conversation) {
    $lastUserMessage = '';
    foreach (array_reverse($conversation) as $msg) {
        if ($msg['role'] === 'user') {
            $lastUserMessage = $msg['text'];
            break;
        }
    }

    $topics = [
        'ai' => ['ai', 'umělá inteligence', 'neuronová', 'strojové učení'],
        'produkty' => ['produkt', 'cena', 'koupit', 'demo'],
        'clanky' => ['článek', 'blog', 'návod']
    ];

    $lowerMessage = strtolower($lastUserMessage);
    foreach ($topics as $topic => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($lowerMessage, $keyword) !== false) {
                return $topic;
            }
        }
    }

    return 'general';
}

// OPRAVA TC-02 (Jazyk): Robustnější funkce pro detekci jazyka v2.2
function detectLanguage($message) {
    if(empty(trim($message))) return 'cs'; // Prázdná zpráva je CS
    // Přidáme více běžných slov a kontrolu prvního slova
    $englishIndicators = ['what', 'how', 'why', 'who', 'where', 'when', 'is', 'are', 'am', 'do', 'does', 'did', 'you', 'i', 'me', 'my', 'your', 'please', 'help', 'product', 'price', 'hello', 'hi', 'hey', 'thank', 'goodbye', 'contact', 'about', 'service', 'feature', 'can', 'could', 'should', 'would', 'the', 'a', 'in', 'on', 'at', 'it', 'for', 'with'];
    $messageLower = trim(strtolower($message));

    // Jednoduchá kontrola - pokud obsahuje jen ASCII a mezery, je to pravděpodobně EN
    if (preg_match('/^[a-zA-Z0-9\s\p{P}]*$/u', $message)) {
         // Ještě ověříme, zda obsahuje nějaké EN indikátory
         $containsIndicator = false;
         foreach ($englishIndicators as $indicator) {
             // Použijeme \b pro celá slova
             if (preg_match('/\b' . preg_quote($indicator) . '\b/i', $messageLower)) {
                 $containsIndicator = true;
                 break;
             }
         }
         if ($containsIndicator) return 'en';
         // Pokud neobsahuje indikátory, ale je ASCII, může to být CS bez diakritiky -> necháme CS
    }

    // Pokud obsahuje non-ASCII (diakritiku), je to pravděpodobně CS
     if (preg_match('/[^\x00-\x7F]/', $message)) {
        return 'cs';
     }

    // Fallback: Pokud selžou předchozí kontroly, zkusíme indikátory
    $wordCount = 0; $englishCount = 0;
    preg_match_all('/\b[a-zA-Z]{2,}\b/', $messageLower, $words); // Jen EN slova 2+
    if (!empty($words[0])) {
        $wordCount = count($words[0]);
        foreach ($words[0] as $word) if (in_array($word, $englishIndicators)) $englishCount++;
        // Citlivější: > 10% indikátorů NEBO (max 6 slov A aspoň 1 indikátor)
        if ($wordCount > 0 && ($englishCount / $wordCount > 0.10 || ($wordCount <= 6 && $englishCount > 0))) return 'en';
    }
    // Kontrola specifických frází na začátku
    if (preg_match('/^\s*(hi|hello|hey|how are|what is|can you|i need|tell me)\b/i', $message)) return 'en';

    return 'cs'; // Výchozí je čeština
}

/**
 * OPRAVA TC-02 (Jazyk) a TC-05 (Více otázek): Funkce upravena
 */
function getSmartAnswer($question, $context, $history, $image = null, $sentiment = 'neutral', $userIntent = 'general', $language = 'cs') {
    // ⬇️ ⬇️ ⬇️ ZDE VLOŽTE SVŮJ API KLÍČ ⬇️ ⬇️ ⬇️
    $API_KEY = ""; // 👈 ZDE VLOŽTE SVŮJ API KLÍČ
    // ⬆️ ⬆️ ⬆️ ZDE VLOŽTE SVŮJ API KLÍČ ⬆️ ⬆️ ⬆️

    $MODEL = "claude-haiku-4-5-20251001"; // Správný model

    if ($API_KEY === "" || empty($API_KEY)) {
        error_log("CHYBA: API klíč není nastaven!");
        return "Omlouvám se, momentálně nemohu odpovědět. Administrátor musí nastavit API klíč.";
    }

    if (strpos($API_KEY, "sk-ant-") === false) {
        error_log("CHYBA: Neplatný formát API klíče: " . substr($API_KEY, 0, 10) . "...");
        return "Chyba: Nebyl zadán platný API klíč pro Claude.";
    }

    $apiUrl = "https://api.anthropic.com/v1/messages";

    $historyString = "";
    foreach($history as $entry) {
        $role = ($entry['role'] === 'user') ? "Uživatel" : "Asistent";
        $historyString .= $role . ": " . $entry['text'] . "\n";
    }

    $tone = "neutrální";
    if ($sentiment === 'positive') $tone = "přátelský a nadšený";
    elseif ($sentiment === 'negative') $tone = "empatický a chápavý";

    $prompt = "Jsi AI asistent pro web AI4NGO (AI pro neziskové organizace).\n\n" .
              "KONTEXT KONVERZACE:\n" .
              "- Nálada uživatele: $sentiment (použij $tone tón)\n" .
              "- Záměr uživatele: $userIntent\n" .
              "- Hloubka konverzace: " . count($history) . " zpráv\n" .
              "- Zaměření: neziskové organizace a AI technologie\n\n" .

              "PRAVIDLA ODPOVÍDÁNÍ:\n" .
              "0. **ABSOLUTNĚ KRITICKÉ: Odpověz POUZE v jazyce '$language'**. Nikdy nepřekládej do jiného jazyka.\n" .
              "1. POKUD ZPRÁVA OBSAHUJE VÍCE OTÁZEK, ODPOVĚZ NA VŠECHNY (můžeš použít odrážky nebo číslovaný seznam).\n" .
              "2. Odpovídej STRUČNĚ (maximálně 2-3 věty, výjimečně 4 pro složitá vysvětlení)\n" .
              "3. Použij poskytnutý kontext z webu AI4NGO pro relevantní odpovědi\n" .
              "4. Pokud odpověď vychází z článku, přidej odkaz ve formátu: 'Více v článku: [Název](URL)'\n" .
              "5. Pro technické otázky o AI používej srozumitelné analogie\n" .
              "6. Pokud nevíš, raději přiznej neznalost než hádej\n" .
              "7. U negativního sentimentu buď obzvlášť empatický\n" .
              "8. Můžeš se inspirovat naučenými odpověďmi z předchozích konverzací\n\n" .

              "HISTORIE KONVERZACE:\n" . $historyString . "\n" .
              "KONTEXT Z WEBU AI4NGO:\n--- KONTEXT ---\n" . $context . "--- KONEC KONTEXTU ---\n\n" .
              "AKTUÁLNÍ OTÁZKA UŽIVATELE:\n" . $question . "\n\n" .
              "Tvoje odpověď (**POVINNĚ v jazyce '$language'**):";

    $contentParts = [];

    if ($image) {
        $contentParts[] = [
            "type" => "image",
            "source" => ["type" => "base64", "media_type" => "image/jpeg", "data" => $image]
        ];
        $prompt = "Uživatel nahrál obrázek. " . $prompt;
    }

    $contentParts[] = ["type" => "text", "text" => $prompt];

    $postData = [
        "model" => $MODEL,
        "max_tokens" => 1024,
        "messages" => [
            ["role" => 'user', "content" => $contentParts]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $API_KEY,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    error_log("API Call Debug: HTTP=$httpcode, cURLError: $curlError, Response(start): " . substr($result ?: '', 0, 500));

    if ($httpcode != 200 || $result === false) {
        error_log("API Error: HTTP=$httpcode, cURLError: $curlError");
        if ($httpcode === 401) return "Chyba ověření. Zkontrolujte API klíč.";
        if ($httpcode === 404) return "API endpoint nebyl nalezen. Model '$MODEL' může být neplatný.";
        if ($httpcode === 429) return "Překročen limit požadavků. Zkuste to za chvíli.";
        return "Dočasně nedostupné. Zkuste to prosím za chvíli. [Chyba: $httpcode]";
    }

    $data = json_decode($result, true);
    if (isset($data['content'][0]['text'])) {
        return $data['content'][0]['text'];
    } else {
        error_log("Unexpected API response structure: " . substr($result, 0, 500));
        return "Omlouvám se, na tento dotaz nemohu odpovědět (nečekaná struktura odpovědi).";
    }
}

function logInteraction($userMessage, $botResponse, $metadata) {
    $userIntent = detectUserIntent($userMessage);

    $logEntry = [
        'timestamp' => date('c'),
        'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'user_intent' => $userIntent,
        'message' => $userMessage,
        'response' => $botResponse,
        'response_time' => round($metadata['response_time'], 3),
        'used_context' => $metadata['used_context'],
        'used_learned_data' => $metadata['used_learned_data'],
        'sentiment' => $metadata['sentiment'],
        'image_uploaded' => $metadata['image_uploaded'],
        'conversation_length' => $metadata['conversation_length'],
        'message_length' => strlen($userMessage),
        'response_length' => strlen($botResponse)
    ];

    if (!file_exists('analytics')) {
        mkdir('analytics', 0755, true);
    }

    $logFile = 'analytics/' . date('Y-m-d') . '_interactions.json';
    file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ",\n", FILE_APPEND | LOCK_EX);

    $textLog = "[" . date('Y-m-d H:i:s') . "] [IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "]\n";
    $textLog .= "[Intent: $userIntent] [Sentiment: " . $metadata['sentiment'] . "] [Learned: " . ($metadata['used_learned_data'] ? 'yes' : 'no') . "]\n";
    $textLog .= "Uživatel: " . $userMessage . "\n";
    $textLog .= "Chatbot: " . str_replace("\n", " ", $botResponse) . "\n";
    $textLog .= "Response time: " . round($metadata['response_time'], 3) . "s | Length: " . strlen($botResponse) . " chars\n";
    $textLog .= "---\n";

    file_put_contents('analytics/chat_log.txt', $textLog, FILE_APPEND | LOCK_EX);
}


// ===================================================================
// ČÁST 2: HLAVNÍ LOGIKA (ZPRACOVÁNÍ POST)
// ===================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Načtení dat z externího JSON souboru
    if (file_exists('knowledge_base.json')) {
        $knowledgeData = json_decode(file_get_contents('knowledge_base.json'), true);
        $articles = $knowledgeData['articles'] ?? [];
        $glossary = $knowledgeData['glossary'] ?? [];
        $products = $knowledgeData['products'] ?? [];
    } else {
        $articles = [['title' => 'Úvod do AI', 'perex' => 'Základní informace o umělé inteligenci', 'url' => '/ai-uvod']];
        $glossary = [['term' => 'AI', 'definition' => 'Umělá inteligence (Artificial Intelligence)']];
        $products = [['title' => 'AI Konzultant', 'use_cases' => 'Personalizované poradenství']];
    }

    $learnedData = loadLearnedData();
    $request_body = file_get_contents('php://input');
    $data = json_decode($request_body, true);
    error_log("Received data: " . print_r($data, true));

    $userMessage = $data['message'] ?? '';
    $userMessageLower = strtolower($userMessage);
    $conversationHistory = $data['history'] ?? [];
    $image = $data['image'] ?? null;
    $reply = null;
    $suggestions = ["Vysvětli neuronové sítě", "Jaké máte produkty?", "Co všechno umíš?"];
    $trimmedMessage = trim($userMessageLower);

    if (!isset($_SESSION['chat_context'])) {
        $_SESSION['chat_context'] = [
            'user_interests' => [], 'asked_questions' => [], 'preferred_language' => 'cs',
            'conversation_topic' => null, 'sentiment_history' => [], 'user_intent' => 'general',
            'mentioned_products' => [], 'mentioned_articles' => [], 'conversation_depth' => 0,
            'last_active' => time()
        ];
    }

    $sentiment = analyzeSentiment($userMessage);
    $userIntent = detectUserIntent($userMessage);
    
    // OPRAVA TC-02 (Jazyk): Detekce jazyka a uložení do session
    $language = detectLanguage($userMessage);
    $_SESSION['chat_context']['preferred_language'] = $language;

    $_SESSION['chat_context']['sentiment_history'][] = ['message' => $userMessage, 'sentiment' => $sentiment, 'timestamp' => time()];
    $_SESSION['chat_context']['user_intent'] = $userIntent;

    // KROK 1: PEVNĚ DANÁ PRAVIDLA
    if (in_array($trimmedMessage, ['ahoj', 'dobrý den', 'čau', 'zdravím', 'cus'])) {
        $time = date('H');
        $greeting = ($time < 12) ? "Dobré ráno" : (($time < 18) ? "Dobré odpoledne" : "Dobrý večer");
        $reply = "$greeting! Jsem AI asistent AI4NGO. Pomůžu vám s AI technologiemi pro neziskové organizace. Na co se chcete zeptat?";
        $suggestions = ["Potřebuji poradit s AI", "Jaké máte nástroje?", "Hledám článek"];
    }
    elseif (strpos($userMessageLower, 'co umíš') !== false || strpos($userMessageLower, 'co všechno umíš') !== false || $trimmedMessage === 'pomoc' || $trimmedMessage === 'nápověda') {
        $reply = "Jsem AI asistent a umím pro vás:\n\n" .
                 "1. 📖 **Odpovídat na dotazy** k tématům z webu AI4NGO.\n" .
                 "2. 🤖 **Vysvětlit obecné pojmy** z oblasti umělé inteligence.\n" .
                 "3. 💡 **Doporučit produkty** a nástroje z naší nabídky.\n" .
                 "4. 🎤 **Mluvit s vámi** - zkuste hlasové ovládání!\n" .
                 "5. 🖼️ **Analyzovat obrázky** - nahrajte screenshot a zeptejte se.\n" .
                 "6. 🧠 **Učím se** z každé konverzace a zlepšuji své odpovědi!";
        $suggestions = generateSmartSuggestions($_SESSION['chat_context'], $conversationHistory);
    }

    // KROK 1b: DETEKCE ZÁMĚRU
    if ($reply === null) {
        switch ($userIntent) {
            case 'greeting':
                $time = date('H');
                // Používáme proměnnou $language, která byla nastavena dříve
                if ($language === 'en') {
                    $greeting = ($time < 12) ? "Good morning" : (($time < 18) ? "Good afternoon" : "Good evening");
                    $reply = "$greeting! I'm the AI assistant for AI4NGO. How can I help you today regarding AI for non-profits?";
                    // Suggestions se generují až na konci
                } else {
                    $greeting = ($time < 12) ? "Dobré ráno" : (($time < 18) ? "Dobré odpoledne" : "Dobrý večer");
                    $reply = "$greeting! Jsem AI asistent AI4NGO. Jak vám dnes mohu pomoci ohledně AI pro neziskové organizace?";
                }
                break;
            case 'farewell':
                $reply = "Děkuji za konverzaci! 🎉 Pokud budete mít další dotazy, jsem tu pro vás. Hezký den!";
                $suggestions = ["Začít novou konverzaci", "Uložit chat", "Kontaktovat tým"];
                break;
            case 'pricing':
                $reply = "Naše řešení nabízíme v různých cenových úrovních podle velikosti organizace. Doporučuji konzultaci pro přesnou cenovou nabídku na míru.";
                $suggestions = ["Objednat demo", "Kontaktovat obchod", "Srovnání verzí"];
                break;
            case 'complaint':
                $reply = "Omlouvám se za problémy. Pokusím se vám pomoci co nejlépe. Můžete popsat, co se nepovedlo?";
                $suggestions = ["Kontaktovat podporu", "Nahlásit chybu", "Návod k řešení"];
                break;
            // OPRAVA TC-06 (Soukromí): Zpracování mazání dat
            case 'delete_data': // OPRAVA TC-06 v2.2
                 try {
                     error_log("Attempting to delete session data (Session ID: " . session_id() . ")");
                     // Jazyk bereme z již nastavené session proměnné
                     $current_lang = $_SESSION['chat_context']['preferred_language'] ?? 'cs';
                     if ($current_lang === 'en') {
                          $reply = "Understood. Your previous conversation session has been cleared. We can start fresh.";
                          $suggestions = ["What is AI?", "What products do you have?", "What can you do?"];
                     } else {
                          $reply = "Rozumím. Vaše předchozí konverzační session byla vymazána. Můžeme začít znovu.";
                          $suggestions = ["Co je to AI?", "Jaké máte produkty?", "Co všechno umíš?"];
                     }
                     $response = ['reply' => $reply, 'suggestions' => $suggestions, 'action' => 'reset_chat'];

                     // Bezpečné zničení session
                     if (session_status() === PHP_SESSION_ACTIVE) {
                         // Nejdříve odstraníme proměnné
                         $_SESSION = array();
                         // Poté zrušíme cookie
                         if (ini_get("session.use_cookies")) {
                             $params = session_get_cookie_params();
                             setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
                         }
                         // Nakonec zničíme session na serveru
                         session_destroy();
                         error_log("Session destroyed successfully (Previous Session ID was: " . session_id() . ")"); // Logujeme ID před zničením
                     } else { error_log("Session was not active, cannot destroy."); }

                     // Vyčištění bufferu a odeslání odpovědi
                     while (ob_get_level() > 0) ob_end_clean(); // Vyčistí všechny úrovně bufferu
                     header('Content-Type: application/json; charset=utf-8'); // Přidáno charset
                     // Použijeme flagy pro ignorování neplatných UTF-8 sekvencí, které mohou vzniknout
                     echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
                     error_log("Delete response sent. Exiting script.");
                     exit(); // Ukončíme HNED

                 } catch (Throwable $e) { // Změna na Throwable pro odchycení i Errorů (např. chyba session handleru)
                      error_log("CRITICAL Error during session deletion: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                      while (ob_get_level() > 0) ob_end_clean(); // Vyčistíme buffer i při chybě
                      // Odeslat nouzovou chybovou odpověď
                      // Ověříme, zda hlavičky ještě nebyly odeslány
                      if (!headers_sent()) {
                           header("HTTP/1.1 500 Internal Server Error"); // Nastavit HTTP status
                           header('Content-Type: application/json; charset=utf-8');
                      }
                      // Poslat JSON chybu, i když hlavičky už byly odeslány (prohlížeč to může ignorovat, ale logovat ano)
                      echo json_encode(['error' => 'Server error during reset.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
                      exit();
                 }
                 // break; // Není potřeba kvůli exit()
        }
    }

    // KROK 2: NAUČENÁ DATA
    if ($reply === null) {
        $learnedReply = getLearnedResponse($userMessage, $learnedData);
        if ($learnedReply) {
            $reply = $learnedReply . "\n\n*(Odpověď založená na předchozích interakcích)*";
        }
    }

    // KROK 3: VOLÁNÍ AI
    if ($reply === null) {
        $relevantContext = findRelevantContext($userMessage, $articles, $glossary, $products);
        $knowledgeBase = buildKnowledgeBase($articles, $glossary, $products, $relevantContext, $learnedData);
        
        // OPRAVA TC-02 (Jazyk): Předání $language do funkce
        $reply = getSmartAnswer($userMessage, $knowledgeBase, $conversationHistory, $image, $sentiment, $userIntent, $language);
        saveInteractionForLearning($userMessage, $reply, $relevantContext);
    }

    // Aktualizace kontextu
    $_SESSION['chat_context']['asked_questions'][] = $userMessage;
    $_SESSION['chat_context']['conversation_topic'] = detectConversationTopic($conversationHistory);
    $_SESSION['chat_context']['conversation_depth']++;
    $_SESSION['chat_context']['last_active'] = time();

    preg_match_all('/\b(AI Konzultant|Data Analyzer|Content Assistant)\b/i', $userMessage, $productMatches);
    if (!empty($productMatches[0])) {
        $_SESSION['chat_context']['mentioned_products'] = array_merge($_SESSION['chat_context']['mentioned_products'], $productMatches[0]);
    }

    $suggestions = generateSmartSuggestions($_SESSION['chat_context'], $conversationHistory);
    $response = ['reply' => $reply, 'suggestions' => $suggestions];
    
    // OPRAVA TC-06 (Soukromí): Přidání akce pro reset frontendu
    if ($userIntent === 'delete_data') {
        $response['action'] = 'reset_chat';
    }

    $metadata = [
        'response_time' => microtime(true) - ($_SERVER["REQUEST_TIME_FLOAT"] ?? microtime(true)),
        'used_context' => !empty($relevantContext),
        'used_learned_data' => !empty($learnedReply),
        'sentiment' => $sentiment,
        'user_intent' => $userIntent,
        'image_uploaded' => !empty($image),
        'conversation_length' => count($conversationHistory) + 1
    ];

    logInteraction($userMessage, $reply, $metadata);

    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit(); // ⚠️ DŮLEŽITÉ: Zastaví vykreslování HTML
}

// ========= KONEC PHP BLOKU =========
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Asistent | AI4NGO</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --text-dark: #1f2937;
            --text-light: #6b7280;
            --bg-light: #f8fafc;
            --white: #ffffff;
            --border-color: #e5e7eb;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: transparent;
            color: var(--text-dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* CHAT WIDGET STYLES */
        .chat-widget {
            position: relative;
            width: 100%;
            height: 100vh;
            max-width: 440px;
            max-height: 750px;
            z-index: 1000;
            font-family: 'Inter', sans-serif;
        }

        .chat-container {
            position: relative;
            bottom: auto;
            right: auto;
            width: 100%;
            height: 100%;
            background: var(--white);
            border-radius: 1rem;
            box-shadow: var(--shadow-lg);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .chat-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-shrink: 0;
        }

        .chat-avatar {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .chat-header-info h3 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0;
        }

        .chat-header-info p {
            font-size: 0.875rem;
            opacity: 0.8;
            margin: 0;
        }

        .chat-messages {
            flex: 1;
            padding: 1rem;
            overflow-y: auto;
            background: var(--bg-light);
        }

        .message {
            margin-bottom: 1rem;
            display: flex;
            flex-direction: column;
        }

        .user-message {
            align-items: flex-end;
        }

        .bot-message {
            align-items: flex-start;
        }

        .message-bubble {
            padding: 0.75rem 1rem;
            border-radius: 1rem;
            max-width: 80%;
            line-height: 1.4;
            font-size: 0.875rem;
            word-wrap: break-word;
            white-space: pre-wrap;
        }

        .user-message .message-bubble {
            background: var(--primary-color);
            color: white;
            border-bottom-right-radius: 0.25rem;
        }

        .bot-message .message-bubble {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--border-color);
            border-bottom-left-radius: 0.25rem;
        }

        .bot-message .message-bubble a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .bot-message .message-bubble a:hover {
            text-decoration: underline;
        }

        .learned-badge {
            display: inline-block;
            background: #10b981;
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.75rem;
            margin-top: 0.5rem;
        }

        .suggestions {
            padding: 0.75rem;
            background: var(--white);
            border-top: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .suggestion-chip {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            color: var(--text-dark);
            padding: 0.5rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .suggestion-chip:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .chat-input-container {
            padding: 1rem;
            background: var(--white);
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
            flex-shrink: 0;
        }

        .chat-input-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .chat-input {
            flex: 1;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            outline: none;
            resize: none;
            max-height: 100px;
            font-family: 'Inter', sans-serif;
        }

        .chat-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .chat-controls {
            display: flex;
            gap: 0.25rem;
            margin-top: 0.5rem;
        }

        .control-btn {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            color: var(--text-light);
            width: 32px;
            height: 32px;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .control-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .control-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .chat-send-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 0.5rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .chat-send-btn:hover {
            background: var(--primary-dark);
        }

        .typing-indicator {
            padding: 1rem;
            background: var(--white);
            border-radius: 1rem;
            border: 1px solid var(--border-color);
            max-width: 80%;
        }

        .typing-dots {
            display: inline-block;
        }

        .typing-dots::after {
            content: '...';
            animation: typingDots 1.5s infinite;
        }

        @keyframes typingDots {
            0%, 33% { content: '.'; }
            34%, 66% { content: '..'; }
            67%, 100% { content: '...'; }
        }

        .progress-bar {
            width: 100%;
            height: 4px;
            background: var(--border-color);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary-color);
            width: 0%;
            transition: width 0.8s ease;
        }
    </style>
</head>
<body>

    <div class="chat-widget">
        <div class="chat-container active" id="chat-container">
            <div class="chat-header">
                <div class="chat-avatar">🤖</div>
                <div class="chat-header-info">
                    <h3>AI Asistent</h3>
                    <p>Online • Pomohu vám</p>
                </div>
            </div>

            <div class="chat-messages" id="chat-messages">
                <!-- Úvodní zpráva -->
                <div class="message bot-message">
                    <div class="message-bubble">
                        Dobrý den! Jsem AI asistent pro neziskové organizace. 🚀<br><br>
                        Můžete se mě ptát na naše služby, články nebo AI technologie. Rád vám pomohu! A ano - učím se z každé konverzace! 🧠
                    </div>
                </div>
            </div>

            <div class="suggestions" id="suggestions">
                <button class="suggestion-chip" onclick="useSuggestion(this)">Co je to AI?</button>
                <button class="suggestion-chip" onclick="useSuggestion(this)">Jaké máte produkty?</button>
                <button class="suggestion-chip" onclick="useSuggestion(this)">Co všechno umíš?</button>
            </div>

            <div class="chat-input-container">
                <div class="chat-input-wrapper">
                    <textarea
                        class="chat-input"
                        id="user-input"
                        placeholder="Napište svůj dotaz..."
                        rows="1"
                    ></textarea>
                    <div class="chat-controls">
                        <button class="control-btn" id="voice-btn" title="Hlasové ovládání">🎤</button>
                        <button class="control-btn" id="image-btn" title="Nahrát obrázek">🖼️</button>
                        <input type="file" id="image-upload" accept="image/*" style="display: none;">
                        <button class="control-btn" id="speaker-btn" title="Přečíst odpověď nahlas">🔊</button>
                    </div>
                </div>
                <button class="chat-send-btn" id="send-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <script>
        // CHAT WIDGET FUNCTIONALITY
        const chatContainer = document.getElementById('chat-container');
        const chatMessages = document.getElementById('chat-messages');
        const userInput = document.getElementById('user-input');
        const sendBtn = document.getElementById('send-btn');
        const suggestionsContainer = document.getElementById('suggestions');
        const voiceBtn = document.getElementById('voice-btn');
        const imageBtn = document.getElementById('image-btn');
        const imageUpload = document.getElementById('image-upload');
        const speakerBtn = document.getElementById('speaker-btn');

        let conversation = [];
        let uploadedImage = null;
        let isRecording = false;
        let recognition = null;
        let lastBotMessage = "";
        let inactivityTimer;

        // Funkce pro použití návrhu
        function useSuggestion(element) {
            const text = element.textContent;
            userInput.value = text;
            sendMessage();
        }

        // Initialize chat
        function initChat() {
            loadConversation();
            if (conversation.length === 0) {
                // Úvodní zpráva je již v HTML, takže jen nastavíme suggestions
                showSuggestions(["Co je to AI?", "Jaké máte produkty?", "Co všechno umíš?"]);
            }

            resetInactivityTimer();
        }

        // Detekce nečinnosti a reset konverzace
        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(() => {
                if (conversation.length > 2) {
                    const message = "Vidím, že jste přestal/a psát. Pokud potřebujete další pomoc, stačí napsat!";
                    addBotMessage(message, ["Obnovit konverzaci", "Nový dotaz", "Potřebuji pomoc"]);
                }
            }, 300000); // 5 minut nečinnosti
        }

        // Send message on button click
        sendBtn.addEventListener('click', sendMessage);

        // Send message on Enter key (but allow Shift+Enter for new line)
        userInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
            resetInactivityTimer();
        });

        // Auto-resize textarea
        userInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            resetInactivityTimer();
        });

        // Voice recognition
        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            recognition = new SpeechRecognition();
            recognition.lang = 'cs-CZ';
            recognition.continuous = false;

            recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript;
                userInput.value = transcript;
                isRecording = false;
                voiceBtn.classList.remove('active');
                resetInactivityTimer();
            };

            recognition.onerror = () => {
                isRecording = false;
                voiceBtn.classList.remove('active');
            };
        } else {
            voiceBtn.style.display = 'none';
        }

        voiceBtn.addEventListener('click', () => {
            if (!recognition) {
                alert('Váš prohlížeč nepodporuje hlasové ovládání.');
                return;
            }

            if (isRecording) {
                recognition.stop();
                isRecording = false;
                voiceBtn.classList.remove('active');
            } else {
                recognition.start();
                isRecording = true;
                voiceBtn.classList.add('active');
            }
            resetInactivityTimer();
        });

        // Image upload
        imageBtn.addEventListener('click', () => {
            imageUpload.click();
            resetInactivityTimer();
        });

        imageUpload.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (event) => {
                    uploadedImage = event.target.result.split(',')[1];
                    imageBtn.classList.add('active');
                    if (userInput.value.trim() === '') {
                        userInput.value = 'Analyzuj tento obrázek';
                    }
                };
                reader.readAsDataURL(file);
            }
            resetInactivityTimer();
        });

        // Text-to-speech
        speakerBtn.addEventListener('click', () => {
            if (!lastBotMessage) {
                alert('Zatím není žádná odpověď k přečtení.');
                return;
            }

            if ('speechSynthesis' in window) {
                const utterance = new SpeechSynthesisUtterance(lastBotMessage);
                utterance.lang = 'cs-CZ';
                utterance.rate = 0.9;

                utterance.onstart = () => {
                    speakerBtn.classList.add('active');
                };
                utterance.onend = () => {
                    speakerBtn.classList.remove('active');
                };

                window.speechSynthesis.speak(utterance);
            } else {
                alert('Váš prohlížeč nepodporuje text-to-speech.');
            }
            resetInactivityTimer();
        });

        // Message handling
        async function sendMessage() {
            const messageText = userInput.value.trim();
            if (messageText === '' && !uploadedImage) return;

            addUserMessage(messageText || "[Obrázek nahrán]");
            userInput.value = '';
            userInput.style.height = 'auto';
            clearSuggestions();
            showTypingIndicator();

            const historyForApi = conversation.slice(-4).map(msg => ({
                role: msg.role,
                text: msg.text
            }));

            const payload = {
                message: messageText,
                history: historyForApi
            };

            if (uploadedImage) {
                payload.image = uploadedImage;
                uploadedImage = null;
                imageBtn.classList.remove('active');
            }

            try {
                console.log('Sending request to server...');
                const response = await fetch('chatbot.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Server error response:', errorText);
                    throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
                }

                const data = await response.json();
                console.log('Received response:', data);

                hideTypingIndicator();
                
                // OPRAVA TC-06 (Soukromí): Zpracování resetu
                if (data.action === 'reset_chat') {
                    chatMessages.innerHTML = ''; // Vymaže chat v prohlížeči
                    conversation = []; // Resetuje lokální historii
                    saveConversation(); // Uloží prázdnou konverzaci
                    // Přidáme úvodní zprávu zpět
                    addBotMessage("Dobrý den! Jsem AI asistent pro neziskové organizace. 🚀\n\nMůžete se mě ptát na naše služby, články nebo AI technologie. Rád vám pomohu! A ano - učím se z každé konverzace! 🧠", ["Co je to AI?", "Jaké máte produkty?", "Co všechno umíš?"], false);
                }
                
                lastBotMessage = data.reply.replace(/<br>/g, "\n").replace(/<\/?[^>]+(>|$)/g, "");
                addBotMessage(data.reply, data.suggestions);
            } catch (error) {
                console.error('Error sending/receiving message:', error);
                hideTypingIndicator();
                addBotMessage('Omlouvám se, nastala chyba při komunikaci se serverem. Zkuste to prosím znovu.', []);
            }

            resetInactivityTimer();
        }

        function addUserMessage(text, save = true) {
            if (save) {
                conversation.push({ role: 'user', text: text });
                saveConversation();
            }

            const messageDiv = document.createElement('div');
            messageDiv.className = 'message user-message';

            const bubble = document.createElement('div');
            bubble.className = 'message-bubble';
            bubble.textContent = text;

            messageDiv.appendChild(bubble);
            chatMessages.appendChild(messageDiv);
            scrollToBottom();
        }

        function addBotMessage(text, suggestions, save = true) {
            if (save) {
                conversation.push({
                    role: 'bot',
                    text: text,
                    suggestions: suggestions
                });
                saveConversation();
            }

            const messageDiv = document.createElement('div');
            messageDiv.className = 'message bot-message';

            const bubble = document.createElement('div');
            bubble.className = 'message-bubble';

            let processedText = text.replace(/\n/g, '<br>');
            processedText = processedText.replace(
                /\[(.*?)\]\((.*?)\)/g,
                '<a href="$2" target="_blank" style="color: #2563eb; text-decoration: none;">$1</a>'
            );

            if (text.includes('*(Odpověď založená na předchozích interakcích)*')) {
                 processedText = processedText.replace('*(Odpověď založená na předchozích interakcích)*', '');
                 processedText += '<div class="learned-badge">🧠 Naučeno z předchozích konverzací</div>';
            }

            bubble.innerHTML = processedText;
            messageDiv.appendChild(bubble);
            chatMessages.appendChild(messageDiv);

            showSuggestions(suggestions);
            scrollToBottom();
        }

        function showSuggestions(suggestions) {
            clearSuggestions();
            if (!suggestions || suggestions.length === 0) return;

            suggestions.forEach(text => {
                const chip = document.createElement('button');
                chip.className = 'suggestion-chip';
                chip.textContent = text;
                chip.onclick = () => useSuggestion(chip);
                suggestionsContainer.appendChild(chip);
            });
        }

        function clearSuggestions() {
            suggestionsContainer.innerHTML = '';
        }

        function showTypingIndicator() {
            if (document.getElementById('typing-indicator')) return;

            const indicator = document.createElement('div');
            indicator.id = 'typing-indicator';
            indicator.className = 'message bot-message';

            const bubble = document.createElement('div');
            bubble.className = 'typing-indicator';
            bubble.innerHTML = `
                <div>🤖 AI asistent přemýšlí<span class="typing-dots"></span></div>
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
            `;

            indicator.appendChild(bubble);
            chatMessages.appendChild(indicator);
            scrollToBottom();

            setTimeout(() => {
                const fill = bubble.querySelector('.progress-fill');
                if (fill) {
                    fill.style.width = '30%';
                    setTimeout(() => {
                        fill.style.width = '70%';
                    }, 500);
                }
            }, 100);
        }

        function hideTypingIndicator() {
            const indicator = document.getElementById('typing-indicator');
            if (indicator) {
                indicator.remove();
            }
        }

        function scrollToBottom() {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function saveConversation() {
            try {
                sessionStorage.setItem('chatConversation', JSON.stringify(conversation));
            } catch (e) {
                console.warn('Could not save conversation to sessionStorage:', e);
            }
        }

        function loadConversation() {
            try {
                const saved = sessionStorage.getItem('chatConversation');
                if (saved) {
                    conversation = JSON.parse(saved);
                    chatMessages.innerHTML = '';

                    conversation.forEach(msg => {
                        if (msg.role === 'user') {
                            addUserMessage(msg.text, false);
                        } else {
                            addBotMessage(msg.text, msg.suggestions || [], false);
                        }
                    });

                    const lastBotMsg = conversation[conversation.length - 1];
                    if (lastBotMsg && lastBotMsg.role === 'bot') {
                        lastBotMessage = lastBotMsg.text.replace(/<br>/g, "\n").replace(/<\/?[^>]+(>|$)/g, "");
                        if (lastBotMsg.suggestions) {
                            showSuggestions(lastBotMsg.suggestions);
                        }
                    }
                }
            } catch (e) {
                console.warn('Could not load conversation from sessionStorage:', e);
                conversation = [];
            }
        }

        // Initialize chat when page loads
        document.addEventListener('DOMContentLoaded', initChat);
    </script>
</body>
</html>