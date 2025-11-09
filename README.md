# AI Chatbot pro neziskové organizace

Pokročilý AI chatbot postavený na technologii Claude AI od Anthropic, speciálně navržený pro neziskové organizace.

## 🚀 Funkce

- **AI konverzace** - Inteligentní odpovědi pomocí Claude AI
- **Samoučící systém** - Učí se z každé konverzace
- **Vícejazyčná podpora** - Automatická detekce češtiny a angličtiny
- **Hlasové ovládání** - Rozpoznávání řeči a text-to-speech
- **Analýza obrázků** - Nahrávání a analýza screenshotů
- **Sentiment analýza** - Rozpoznání nálady uživatele
- **Rate limiting** - Ochrana před spam útoky
- **Analytics** - Detailní logování konverzací

## 📋 Požadavky

- PHP 7.4 nebo vyšší
- cURL extension
- JSON extension
- Session support
- Zápis do souborového systému (pro learned_data.json a analytics)

## 🔧 Instalace

### 1. Stáhněte soubory

```bash
git clone https://github.com/Lukedaca/Chatbot-pro-neziskovky.git
cd Chatbot-pro-neziskovky
```

### 2. Nastavte API klíč

Máte dvě možnosti:

**Možnost A: Environment proměnná (doporučeno pro produkci)**

```bash
export CLAUDE_API_KEY="sk-ant-your-api-key-here"
```

Nebo v Apache `.htaccess`:
```apache
SetEnv CLAUDE_API_KEY sk-ant-your-api-key-here
```

**Možnost B: Přímé zadání v kódu (pro testování)**

Otevřete `chatbot.php` a na řádku 525 zadejte svůj API klíč:

```php
$API_KEY = "sk-ant-your-api-key-here";
```

### 3. Získání API klíče

1. Jděte na https://console.anthropic.com/
2. Vytvořte účet nebo se přihlaste
3. Přejděte do sekce "API Keys"
4. Vytvořte nový API klíč
5. Zkopírujte klíč (začíná `sk-ant-`)

### 4. Nastavte oprávnění

```bash
chmod 755 chatbot.php
chmod 755 knowledge_base.json
mkdir analytics
chmod 777 analytics
touch learned_data.json
chmod 666 learned_data.json
```

### 5. Spusťte aplikaci

Nahrajte soubory na webový server nebo spusťte lokálně:

```bash
php -S localhost:8000 chatbot.php
```

Otevřete v prohlížeči: `http://localhost:8000/chatbot.php`

## 🔒 Bezpečnost

Aplikace obsahuje několik bezpečnostních funkcí:

- **Rate Limiting** - Max 60 požadavků za hodinu na session
- **Input Validation** - Validace a sanitizace všech vstupů
- **Session Security** - HttpOnly a Secure cookies, session regeneration
- **CSP Headers** - Content Security Policy
- **Error Handling** - Bezpečné zacházení s chybami bez odhalení interních detailů
- **SSL Verification** - Ověřování SSL certifikátů při API voláních

## 📊 Konfigurace

### Rate Limiting

Upravte v `chatbot.php` na řádku 35:

```php
$max_requests = 60; // Max požadavků za hodinu
$time_window = 3600; // 1 hodina v sekundách
```

### Maximální délka zprávy

Upravte v `chatbot.php` na řádku 79:

```php
if (mb_strlen($message, 'UTF-8') > 10000) {
```

### Knowledge Base

Upravte `knowledge_base.json` a přidejte vlastní:
- Články
- Slovník pojmů
- Produkty/služby

## 📁 Struktura souborů

```
Chatbot-pro-neziskovky/
├── chatbot.php              # Hlavní aplikace
├── knowledge_base.json      # Databáze znalostí
├── learned_data.json        # Naučená data (vytvoří se automaticky)
├── analytics/               # Logy a analytika (vytvoří se automaticky)
│   ├── YYYY-MM-DD_interactions.json
│   └── chat_log.txt
└── README.md               # Tento soubor
```

## 🐛 Řešení problémů

### Chatbot neodpovídá

1. Zkontrolujte, zda je API klíč správně nastaven
2. Ověřte PHP error log: `tail -f /var/log/php_errors.log`
3. Zkontrolujte browser console (F12) pro JavaScript chyby

### Chyba "API klíč není nastaven"

- Ujistěte se, že API klíč je nastaven buď jako env proměnná nebo přímo v kódu
- API klíč musí začínat `sk-ant-`

### Chyba oprávnění

```bash
chmod 777 analytics
chmod 666 learned_data.json
```

### Rate limit překročen

Počkejte 1 hodinu nebo resetujte session:
- V prohlížeči: Smazat cookies
- Nebo řekněte chatbotovi: "Vymaž moji konverzaci"

## 📝 Analytics

Aplikace loguje všechny interakce do:

- `analytics/YYYY-MM-DD_interactions.json` - Strukturovaný JSON log
- `analytics/chat_log.txt` - Textový log pro rychlé čtení

Sledované metriky:
- User intent
- Sentiment
- Response time
- Message lengths
- Použití kontextu
- Použití naučených dat

## 🤝 Přispívání

Příspěvky jsou vítány! Prosím:
1. Forkněte repozitář
2. Vytvořte feature branch (`git checkout -b feature/amazing-feature`)
3. Commitněte změny (`git commit -m 'Add amazing feature'`)
4. Pushněte branch (`git push origin feature/amazing-feature`)
5. Otevřete Pull Request

## 📜 Licence

Tento projekt je open-source a dostupný pod MIT licencí.

## 👤 Autor

Lukáš Drštička (@Lukedaca)

## 🙏 Poděkování

- Anthropic za Claude AI API
- Komunita open-source vývojářů
- Neziskové organizace za inspiraci

## 📞 Podpora

Máte otázky nebo problémy?
- Otevřete issue na GitHubu
- Kontaktujte autora

---

**Poznámka:** Tento chatbot používá Claude AI API, které je placené. Sledujte své využití na https://console.anthropic.com/
