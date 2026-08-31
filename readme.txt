========================================================================
Scrum Estimation Poker
========================================================================

PROJEKT-BESCHREIBUNG:
Planning-Poker-Webanwendung mit PHP und AJAX-Polling. Es gibt keine
Datenbank. Die Identität läuft über PHP-Sessions, der gemeinsame
Raumzustand steht in rooms.json (mit Dateisperre).

FEATURES:
- Login mit Name, Raum, Rolle und Sprache (Deutsch, Englisch, Ungarisch)
- URL-Parameter user, room, role, lang (bidirektional; vollständige
  Parameter überspringen die Login-Maske)
- Raumersteller wird Moderator (oder Admin, falls so gewählt)
- Weitere Teilnehmer treten als User bei; Mods/Admins ändern Rollen
- Mehrere Kartendecks parallel (Fibonacci, T-Shirt, Personentage)
- Aufdecken startet den Countdown (Default 5 Sekunden); danach Sperre
- Ban/Unban, Clear für Abwesende, Raumliste mit Löschen (Admin)
- Jeder Admin hat ein eigenes Passwort (Default „geheim“); Hash am Teilnehmer
- Statistik: Anzahl, Mittelwert, Median, Modus, Empfehlung, Verteilung
- Light/Dark Mode, Glassmorphism, keine Grüntöne
- XSS-sicheres Rendering, Session-gebundene Rechte

INSTALLATION & START:
1. Dateien auf einen PHP-Webserver kopieren (PHP >= 7.4, mbstring empfohlen).
2. Schreibrechte auf rooms.json bzw. das Projektverzeichnis erteilen.
3. Aufruf z. B. http://localhost/scrumpoker/index.php

Struktur:
    ├── lang/de.json, en.json, hu.json
    ├── cards.json
    ├── index.js
    ├── index.php
    ├── lib.php
    ├── rooms.json
    ├── style.css
    ├── tests.php
    └── README.txt

Tests:
    php tests.php

LIZENZ:
MIT License
========================================================================
