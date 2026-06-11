**🇩🇪 Deutsch** | [🇬🇧 English](README.en.md)

# Contao Form CSRF Fix

Behebt das Problem **„Ungültiges Anfrage-Token" (HTTP 400)**, auf das
Erstbesucher beim Absenden eines Contao-Formulars stoßen können — kein
Contao-Bug, sondern gewolltes Core-Verhalten zugunsten des Caches, aber ein
echtes Problem für alle, die zuverlässige Formulare über Cache-Optimierung
stellen. Rein serverseitig, ohne Konfiguration. Contao 4.13 – 5.x.

Zugehöriges Core-Issue: [contao/contao#2820](https://github.com/contao/contao/issues/2820)

## Das Wichtigste in Kürze

**Das Problem:** Öffnet jemand eine Formularseite als allerersten Kontakt mit
der Website (Werbe-Link, Newsletter, direkt geteilter Event-Link) und füllt
das Formular gleich aus, kann das Absenden mit „Ungültiges Anfrage-Token"
scheitern. Auslöser ist **JavaScript, das nebenbei Cookies setzt** — Matomo,
Google Analytics, Meta Pixel, Consent-Banner, Chat-Widgets, Heatmap-Tools …
Je mehr solcher Skripte eine Seite einbindet, desto wahrscheinlicher trifft
es Besucher. Die Anmeldung geht verloren; nicht jeder versucht es erneut.

**Warum ist das nicht längst im Core gefixt?** Es ist kein Bug im engeren
Sinn, sondern eine bewusste **Design-Entscheidung pro Shared Cache**: Contao
möchte auch Seiten mit Formularen aus dem Seiten-Cache ausliefern können —
eine gespeicherte Kopie für alle. Dafür bekommen Erstbesucher bewusst noch
kein CSRF-Cookie. Unter dieser Vorgabe ist das Problem prinzipiell nicht
vollständig lösbar (eine Cache-Kopie für alle und individuelle Token für
jeden schließen sich aus); der Core mildert es nur mit einer Liste bekannter
Tracking-Cookies, die nie vollständig sein kann.

**Was dieses Bundle tut:** Es dreht die Priorität bewusst um — **zuverlässige
Formulare vor maximalem Cache**. Seiten, die ein Formular enthalten, werden
vom Shared Cache ausgenommen und immer frisch ausgeliefert; dafür bekommt
jeder Besucher schon beim allerersten Aufruf das vollständige Token-Paar
(Token im Formular + passendes Cookie). Danach kann Tracking-JavaScript
setzen, was es will: **Der erste Absende-Versuch funktioniert immer.** Alle
Seiten ohne Formular bleiben unverändert voll gecacht. Der CSRF-Schutz selbst
bleibt vollständig aktiv.

### Wann solltest du das Bundle einsetzen — und wann nicht?

✅ **Einsetzen**, wenn Formularseiten **direkt aufgerufen** werden und unter
keinen Umständen scheitern dürfen:
- Event-/Kursanmeldungen, deren Links direkt verteilt werden (Ads, Newsletter, Social Media, QR-Codes)
- Beworbene Landingpages mit Formular
- Anmelde-/Buchungs-/Bestellstrecken, bei denen jede verlorene Einsendung Geld kostet
- Generell: Formular-Funktion hat Priorität, Speed-Optimierung ist nachrangig

➖ **Verzichtbar**, wenn deine Formulare praktisch nur über die interne
Navigation erreicht werden (z. B. ein klassisches Kontaktformular, das
niemand direkt verlinkt): Diese Besucher haben beim Erreichen des Formulars
längst Cookies von den vorher besuchten Seiten — das Token-Paar ist dann
schon da und das Problem tritt im Normalfall gar nicht auf. Wer hier
maximale Cache-Trefferquote will, braucht das Bundle nicht.
(Schaden richtet es auch dort keinen an — es kostet nur den Seiten-Cache der
Formularseiten. Und auf Contao < 5.3.31 mit aktivem Seiten-Cache konnte der
Fehler in bestimmten Cookie-Konstellationen sogar Navigations-Besucher
treffen — im Zweifel: installieren.)

### Was macht es genau?

Contaos CSRF-Schutz funktioniert wie ein Türsteher mit Ticket-System: Das
Formular enthält ein Ticket (Token), der Browser einen passenden Stempel
(Cookie) — beim Absenden muss beides zusammenpassen. Erstbesucher ohne
Cookies bekommen absichtlich **kein** Paar („wer nichts hat, ist harmlos und
wird durchgewinkt") — so bleibt die Seite cachebar. Setzt aber zwischen
Seitenaufruf und Absenden irgendein Skript irgendein Cookie, sagt der
Türsteher plötzlich „Kontrolle!" — und der Besucher hat nie ein Ticket
bekommen. Abgewiesen, Fehler 400.

Das Bundle sorgt dafür, dass auf Formularseiten **jeder sofort beim ersten
Aufruf** Ticket und Stempel bekommt. Es erfindet dafür nichts Neues, sondern
löst einen Request früher exakt den Mechanismus aus, den Contao für alle
Besucher mit Cookies ohnehin benutzt. Der Preis: Diese Seiten können nicht
mehr als Eine-Kopie-für-alle aus dem Cache kommen — sie werden pro Besucher
gerendert, wie es z. B. auch bei Warenkörben selbstverständlich ist.

## Installation

Über den **Contao Manager**: nach `mandrael/contao-form-csrf-fix` suchen,
installieren, fertig. Oder per **Composer**:

```bash
composer require mandrael/contao-form-csrf-fix
```

Danach wie üblich den Anwendungs-Cache leeren (der Contao Manager erledigt
das automatisch; Konsole: `vendor/bin/contao-console cache:clear`).
Keine Konfiguration nötig.

> **Hinweis:** Falls du diesen Fix bereits als App-Code betreibst (eigener
> Listener in `src/` nach demselben Muster), entferne die App-Version beim
> Umstieg — sonst laufen zwei identische Listener.

**Ohne Contao Manager** (reine Symfony-Anwendung mit Contao als Bundle):
zusätzlich in der `config/bundles.php` registrieren:

```php
Mandrael\ContaoFormCsrfFix\ContaoFormCsrfFixBundle::class => ['all' => true],
```

## Funktioniert es? (Verifikation)

Optional einen Diagnose-Header aktivieren (`config/config.yaml` der Installation):

```yaml
parameters:
    contao_form_csrf_fix.diagnostic_header: true
```

Dann eine Formularseite ohne Cookies abrufen:

```bash
curl -sD - -o /dev/null https://example.com/deine-formularseite | grep -iE 'x-contao-csrf-fix|set-cookie|cache-control'
```

Erwartet: `X-Contao-Csrf-Fix: 1`, `Set-Cookie: csrf_…`, `Cache-Control: … private`
— und im HTML ein nicht-leerer `REQUEST_TOKEN`-Wert. Eine Seite *ohne*
Formular darf nichts davon zeigen und bleibt cachebar.

## Kompatibilität

| Contao-Version | Token aus HTML entfernt | Erstbesucher-400-Bug | Bundle hilft |
|---|---|---|---|
| 4.13.x | ja | ja (schärfste Form) | ✅ |
| 5.0 – 5.3.30, 5.4, 5.5.0 – 5.5.6 | ja | ja | ✅ |
| 5.3.31+, 5.5.7+, 5.6.x, 5.7.x | nein ([#8162](https://github.com/contao/contao/pull/8162)) | ja (Cookie-Race bleibt) | ✅ |

PHP ≥ 8.1 (CI-getestet auf 8.1–8.4, Vorab-Test 8.5). EOL-Contao-Versionen (4.13, 5.0–5.2, 5.4–5.6) best-effort — die
richtige Lösung dort ist das Upgrade auf eine unterstützte LTS.

## Deinstallation / Rollback

```bash
composer remove mandrael/contao-form-csrf-fix
vendor/bin/contao-console cache:clear
```

Das Bundle hinterlässt keinerlei Daten — danach gilt wieder exakt das
Standard-Verhalten von Contao (inklusive des Bugs).

## FAQ

**Kollidiert das mit Consent-Management / DSGVO?**
Nein. Das `csrf_*`-Cookie ist für den angefragten Dienst (Formular-Versand)
technisch notwendig und braucht keine Einwilligung. Empfehlung: im
Consent-Tool whitelisten und in der Datenschutzerklärung aufführen.

**Warum nicht einfach das Caching der Formularseiten abschalten?**
Das allein behebt den Bug nicht: Das fehlende `csrf_*`-Cookie wird durch die
*Abwesenheit von Cookies im Request* ausgelöst, nicht durch den Cache.

**Warum kein Token-Nachladen per JavaScript?**
Das würde gegen genau die Skripte anrennen, die das Problem verursachen, und
für Besucher ohne JavaScript nicht funktionieren.

**Was ist mit Besuchern, die Cookies komplett blockieren?**
Deren POST kommt ohne Cookies an und fällt wie bisher unter Contaos
Skip-Regel. Funktioniert.

---

## Technische Details (für Entwickler)

**Der Mechanismus im Core.** Contaos CSRF-Schutz ist ein
Double-Submit-Cookie-Verfahren: Das Formular enthält den Token-Wert, das
`csrf_*`-Cookie denselben Wert; bei POST wird verglichen
(`MemoryTokenStorage` wird pro Request aus den `csrf_*`-Cookies
initialisiert). Zwei Besonderheiten machen das System cache-freundlich —
und anfällig:

1. **Lazy-Cookie:** `CsrfTokenCookieSubscriber::onKernelResponse()` setzt das
   `csrf_*`-Cookie nur, wenn `requiresCsrf()` wahr ist — d. h. wenn der
   Request bereits ein Nicht-CSRF-Cookie trägt (oder die Response Cookies
   setzt). Cookie-lose Erstbesucher bekommen **kein** Cookie; in
   Contao < 5.3.31 werden zusätzlich alle gerenderten Token-Werte per
   `str_replace` aus dem HTML entfernt, damit die Seite shared-cachebar ist.
2. **Skip-Regel:** `ContaoCsrfTokenManager::canSkipTokenValidation()`
   überspringt die POST-Validierung nur bei **null Cookies** (oder exakt nur
   dem `csrf_*`-Cookie) und leerer Session. Ein einziges fremdes Cookie —
   egal welches — erzwingt die Validierung.

**Die Race:** GET ohne Cookies → kein `csrf_*`-Cookie (ggf. Token gestrippt)
→ Tracking-/Consent-JS setzt client-seitig Cookies → POST trägt Cookies →
Validierung erzwungen → Token-/Cookie-Paar unvollständig →
`InvalidRequestTokenException` → 400. Der Core-Workaround (Deny-Liste im
`StripCookiesSubscriber`, [#2876](https://github.com/contao/contao/pull/2876))
filtert nur *bekannte* Tracking-Cookies vor dem Cache-Lookup bzw. der
Weitergabe an die App — unbekannte Cookies (eigene Consent-Tools, Chat-Widgets,
Heatmaps, neue Tracker) reißen die Lücke sofort wieder auf. Auf
Contao < 5.3.31 mit aktivem Seiten-Cache kann zudem ein Besucher, der *nur*
Deny-Liste-Cookies trägt, die token-gestrippte Cache-Variante ausgeliefert
bekommen — dann trifft der Fehler auch Navigations-Besucher.

**Was der Listener ändert.** Ein einzelner `kernel.response`-Listener, der
unmittelbar **vor** dem Core-`CsrfTokenCookieSubscriber` läuft. Seine
Priorität wird nicht hartkodiert, sondern zur Container-Compile-Zeit aus dem
Core gelesen (Core-Priorität + 2), weil sie sich zwischen Contao-Versionen
unterscheidet (−1006 in 4.13 und 5.3.31+, −832 in 5.0 – 5.3.30). Für
erfolgreiche Frontend-HTML-Responses (Main-Request, kein `_token_check=false`,
Content-Type `text/html`, Body vorhanden) prüft er, ob der Body einen
**tatsächlich gerenderten** Token enthält — Abgleich gegen
`ContaoCsrfTokenManager::getUsedTokenValues()`, also exakt die Datenquelle,
die der Core selbst fürs Stripping benutzt (deckt `REQUEST_TOKEN`-Inputs wie
`{{request_token}}` in Inline-JS ab). Nur dann:

1. **`$response->setPrivate()` — immer.** Token-Seiten dürfen nie in den
   Shared Cache. Das schließt auch eine vorbestehende Cache-Poisoning-Lücke
   des Core: Trägt ein Request ein unverändertes `csrf_*`-Cookie plus ein
   weiteres Cookie, sendet `setCookies()` kein `Set-Cookie`, der
   `MakeResponsePrivateListener` greift nicht, und eine Response mit
   nutzergebundenem Token könnte als `public` gespeichert werden.
2. **Marker-Cookie ins Request-Bag** (nur wenn der Request ausschließlich
   `csrf_*`-Cookies oder gar keine trägt): `$request->cookies->set(…)` mit
   einem Namen, der garantiert nicht mit dem konfigurierten
   `%contao.csrf_cookie_prefix%` beginnt (bei exotischen Prefixen wird der
   Name automatisch gepolstert). Der Core-Subscriber liest die
   Request-Cookies erst nach uns, hält den Request deshalb für
   Cookie-tragend und nimmt seinen normalen `setCookies()`-Pfad: Token
   bleibt im HTML, `csrf_*`-Cookie wird gesetzt. Das Marker-Cookie existiert
   nur in der serverseitigen Request-Repräsentation — es wird **nie** an den
   Browser gesendet (der HTTP-Cache-Kernel forwarded ohnehin nur einen Klon).

**Warum das den CSRF-Schutz nicht schwächt:** Es wird keine Validierung
deaktiviert oder Bedingung aufgeweicht. Der Listener löst ausschließlich den
Code-Pfad aus, den der Core für jeden Besucher mit irgendeinem Cookie ohnehin
nimmt — nur einen Request früher. Cookie-lose POSTs (Skip-Pfad), Ajax-POSTs,
Backend-Requests und Routen mit `_token_check=false` bleiben unberührt.

**Kosten:** Pro Frontend-HTML-Response ein `strpos` je verwendetem Token-Wert
(Mikrosekunden); Formularseiten treffen immer PHP statt des Shared Caches.
Seiten ohne gerendertes Token sind komplett unberührt.

**Genutzte APIs** (von 4.13 bis 5.7 identisch, alle public):
`ContaoCsrfTokenManager::getUsedTokenValues()`,
`ScopeMatcher::isFrontendMainRequest()`, Parameter
`contao.csrf_cookie_prefix`, Service-IDs `contao.csrf.token_manager` /
`contao.routing.scope_matcher`.

## Support & Mitwirken

Fehler oder Fragen bitte als
[GitHub-Issue](https://github.com/mandrael/contao-form-csrf-fix/issues)
melden. Pull Requests willkommen. Dieses Bundle ist ein Community-Projekt und
steht in keiner offiziellen Verbindung zur Contao GmbH oder zum
Contao-Core-Team.

## Lizenz

MIT
