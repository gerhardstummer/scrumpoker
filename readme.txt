========================================================================
Scrum Estimation Poker - Modern Glassmorphism Web-Application
========================================================================

PROJEKT-BESCHREIBUNG:
Dieses Projekt realisiert eine voll funktionsfähige, echtzeitfähige Scrum-
Estimation-Poker Webanwendung auf Basis von PHP und AJAX (Long Polling).
Das System verwendet absichtlich KEINE Datenbank, sondern persistiert alle 
Zustände und Daten über PHP-Sessions sowie eine Dateisynchronisation in 
Strukturierte JSON-Dateien.

FEATURES:
- Responsive Zwei-Spalten-Layout im modernen, flachen Glassmorphism / Soft-UI Design.
- Rollenbasiertes Rechtemodell (User, Moderator, Admin).
- Dynamisches Sprachsystem über entkoppelte JSON-Sprachdateien im Verzeichnis "./lang".
- Multi-Deck-Unterstützung (Fibonacci, T-Shirt-Größen und Personentage) parallel nutzbar.
- Automatischer, synchronisierter Circular-Countdown (SVG) beim Aufdecken der Karten.
- Komplett gründen-bereinigtes Farbschema (ausschließlich Graustufen und Electric Blue).
- Bidirektionale URL-Parameter Synchronisation (direkter Login via Link möglich).
- Ausfallsichere Statistik-Engine für gemischte und nicht-numerische Daten.
- Sicherheitsfunktionen wie das Kicken/Bannen von Usern im laufenden Raum.

INSTALLATION & START:
1. Kopiere alle Projektdateien auf einen Webserver mit PHP-Unterstützung (PHP >= 7.4 empfohlen).
2. Stelle sicher, dass der Webserver Schreibrechte im Projektverzeichnis besitzt, um die Datei 
   "rooms.json" modifizieren zu können.
3. Struktur der Installation:
    ├── lang/
    │   ├── de.json
    │   ├── en.json
    │   └── hu.json
    ├── cards.json
    ├── index.js
    ├── index.php
    ├── README.txt
    ├── rooms.json
    └── style.css
4. Rufe das Projekt über den Browser auf (z. B. http://localhost/scrumpoker/index.php).

LIZENZ:
MIT License - Frei verwendbar und modifizierbar für agile Projektteams.
========================================================================