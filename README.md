# Requirements:
PHP 8.0+
[With these extensions:]
- pdo_sqlite
- mbstring
- curl

SQLite 3.9+
[php -m | grep sqlite]

Web Server (Apache, Nginx + PHP-FPM, etc.)
[php -S localhost:8080]

LLM Backend (Requires to Install Ollama)
[ollama pull llama3] (runs by default on http://localhost:1234)

Composer and Browser are optionals
