# Kizami: technische Grundlage für den Datenschutztext

(English version: [PRIVACY.md](../PRIVACY.md). Optionen heißen `sayamaapps.kizami.*` (bis 1.x: `kizami.*`); die Namen
stehen im [README](../README.md#options).)

Dieser Baustein beschreibt die Implementierung. Rechtsgrundlage, Interessenabwägung,
Aufbewahrungsdauer, Betroffenenrechte und Angaben zum Hosting müssen vor Veröffentlichung
für den konkreten Betrieb fachlich geprüft und ergänzt werden. Der Text ist keine
Zusicherung, dass eine Einwilligung in jedem Einsatz entbehrlich ist.

## Reichweitenmessung — technischer Textbaustein

Wir zählen Seitenaufrufe und Klicks auf ausgewählte Links auf unserem Webserver.
Optional meldet die Bildergalerie per JavaScript das bewusste Öffnen ausgewählter
Fotos in der Großansicht, einschließlich Weiterblättern, an denselben Server.
Bloßes Laden oder Vorladen eines Bildes löst keine solche Meldung aus.
Außerdem meldet ein kleines Skript beim Verlassen oder Wechseln der Seite, wie
viele Sekunden sie aktiv sichtbar war; gespeichert werden nur Seite und
Sekundenzahl zusammen mit dem Tageskennzeichen.
Optional meldet ein kleines Skript von unserem eigenen Server ausgewählte Aktionen
auf der Seite an denselben Server, etwa das Herunterladen einer Datei, das Starten
eines Videos oder Klicks auf Links zu unseren Profilen in sozialen Netzwerken.
Gespeichert werden die Art der Aktion, die Seite, der Zeitpunkt, das
Tageskennzeichen und, nur bei dafür freigegebenen Aktionen, eine kurze
Zusatzangabe wie Dateiname, Plattform oder Titel (höchstens 100 Zeichen). Bei
Webadressen bleiben davon nur Domain und Pfad, ohne Parameter und Sprungmarke;
E-Mail-Adressen und Telefonnummern aus `mailto:`- oder `tel:`-Links werden nicht
gespeichert. Jede Aktion wird je Seitenaufruf höchstens einmal gemeldet.
Ausgewählte Aktionen kann auch der Webserver selbst beim Aufruf einer Seite
zählen, etwa den Aufruf über einen Kurzlink; gespeichert werden dieselben Angaben.
Das Modul setzt keine Analyse-Cookies, verwendet keinen Browser-Speicher und lädt
keinen externen Analysedienst. Der Hostinganbieter betreibt den Server; Angaben
zu dessen eigenen Protokollen und Datenverarbeitung sind gesondert zu beachten.

Gespeichert werden Seitenpfad, Zeitpunkt, die Domain der verweisenden Website,
Kampagnenkennzeichen aus dem Link (utm_source, utm_medium, utm_campaign sowie,
falls konfiguriert, ein weiterer Kampagnenparameter), Name
und Herkunftsseite sowie die Position ausgewählter Weiterleitungs-Klicks und ein
Tageskennzeichen. Bei aktivierter Bildzählung werden zusätzlich eine festgelegte
Bildkennung und die Seite der ersten Großansicht gespeichert. Dasselbe Foto zählt
je Tageskennzeichen nur einmal. Diese Meldungen enthalten weder Cookies noch
Browser-Speicherwerte; IP-Adresse und Browserkennung empfängt der Server wie bei
einem normalen Seitenaufruf.
Hinzu kommen eine grobe Geräteklasse (Handy, Tablet, Desktop) aus der
Browserkennung und Aufrufe nicht vorhandener Seiten (Fehlseiten) mit dem
angefragten Pfad. Fehlseiten zählen nicht als Besuche.
Als Besuch zählt ein Tageskennzeichen mit mindestens einem Seitenaufruf.
Direkte Weiterleitungsaufrufe ohne vorherigen Seitenaufruf werden separat
ausgewertet. Ein Weiterleitungs-Klick wird nur gespeichert, wenn der Browser eine
Nutzeraktion bestätigt (Kopfzeile `Sec-Fetch-User`) oder dasselbe Tageskennzeichen
am selben Tag bereits eine Seite aufgerufen hat. Andere Aufrufe werden weitergeleitet,
aber nicht als Ereignis gespeichert; es wird lediglich eine Tagessumme solcher
Aufrufe ohne weitere Angaben hochgezählt. Kontaktquoten beziehen sich auf Kontaktaktionen nach dem ersten
Seitenaufruf am selben Tag; mehrere Klicks erhöhen den Anteil nicht.
Aus der ersten erfassten Seite je Tageskennzeichen ermitteln wir die Einstiegsseite
und Herkunft. Spätere Kontaktklicks werden ihr ausschließlich innerhalb desselben
Berliner Kalendertages zugeordnet. Ein Tagesbesuch ist keine einzelne Sitzung;
mehrere Besuche eines Gerätes am selben Tag können zusammenfallen.
Interne Verweise werden als interne Navigation markiert. URL-Abfrageparameter
werden außer den genannten Kampagnenkennzeichen nicht gespeichert. Telefon-Klicks
sagen nichts darüber aus, ob ein Gespräch zustande gekommen ist.

Für das Tageskennzeichen werden die vom Server empfangene IP-Adresse und
Browserkennung mit dem Datum und einem geheimen, täglich erneuerten Schlüssel
verrechnet. Die unveränderte IP-Adresse und Browserkennung werden in der
Kennzahlen-Datenbank nicht gespeichert. Der Schlüssel des Vortags wird in der
aktiven Installation überschrieben: beim ersten erfassten Aufruf eines Tages
und beim Abruf des Snapshots. Das Öffnen von Dashboard und Report ändert den Schlüssel nicht. Ohne Ersatz
gelöscht wird er bei jedem Seitenaufruf, auch bei einem ausgefilterten (Do Not
Track, Global Privacy Control, Bot), und durch den Wartungsjob. Ohne einen
dieser Anlässe, etwa bei einer Seite ohne Aufrufe, kann der bisherige Schlüssel
liegen bleiben. Das verringert die Verknüpfbarkeit zwischen
Tagen; es garantiert keine vollständige Anonymität. Tageskennzeichen erlauben
nur eine ungefähre Geräteschätzung. Dasselbe Gerät kann an mehreren Tagen erneut
gezählt werden. Tagesgrenzen verwenden Europe/Berlin.

Sendet der Browser Do Not Track oder Global Privacy Control mit dem Wert 1,
entfällt die Erfassung durch dieses Modul. Bekannte Bots werden ausgefiltert.

Einzelereignisse werden bei der Bereinigung nach 13 Monaten (einstellbar über `sayamaapps.kizami.retentionMonths`) aus der aktiven
Datenbank entfernt. Die Zeilen werden beim ersten erfassten Aufruf eines Tages
und im Wartungsjob entfernt. Reste gelöschter Inhalte in freien Seiten der
Datenbankdatei, auch aus früheren Versionen, werden entfernt, wenn die Datei
neu aufgebaut wird: in der Regel täglich, nachdem eine Seite ausgeliefert ist,
spätestens beim ersten erfassten Aufruf, nachdem der letzte gelungene
Neuaufbau mehr als zwei Tage zurückliegt, sofern der Neuaufbau gelingt;
scheitert er wiederholt, zeigt das Dashboard eine Warnung. Auch der
Wartungsjob (`maintenance.php compact`) baut die Datei neu auf. Neue Snapshots
werden stets ohne solche Reste neu aufgebaut.
Kann ein Aufruf nicht sofort gespeichert werden, weil die Datenbank gesperrt
ist, liegt er mit denselben Angaben in einer Zwischendatei neben der Datenbank,
bis er eingelesen ist; die Zwischendatei fasst höchstens 5 MB.
Dateisystem- und Hoster-Snapshots liegen außerhalb der Kontrolle der Anwendung.
Vorher werden vollständige Tage in Tageszahlen zusammengefasst:
Datum, Anzahl der Seitenaufrufe, Gesamtzahl der Weiterleitungs-Klicks und Summe
der für den jeweiligen Tag geschätzten Geräte. Diese Zählwerte bleiben für
Gesamtzahlen und langfristige Monatsvergleiche erhalten. Gerätekennzeichen,
Geräteklassen, Fehlseiten, Bildöffnungen, Aktionen und ihre Zusatzangaben, Linkpositionen, genaue Uhrzeiten, Seitenpfade, Weiterleitungsziele, Herkunft und Kampagnenangaben
werden nicht in diese Zusammenfassung übernommen. Gemeldete Aktionen werden nach
derselben Frist ohne Zusammenfassung gelöscht.

Die Bereinigung läuft beim ersten erfassten Aufruf eines Tages und, falls eingerichtet, über einen Wartungsjob (`maintenance.php`). Der Aufruf fasst zusammen und löscht die Zeilen; die Datenbankdatei wird wie oben beschrieben nach der Auslieferung einer Seite neu aufgebaut. Das Öffnen von Dashboard und Report bereinigt nicht.
Ohne solche Aufrufe kann sich die Bereinigung verzögern. Sicherungen brauchen
eine eigene, begrenzte Aufbewahrungsfrist und dürfen die täglichen Schlüssel
nicht archivieren.

## Betrieb (nicht als Seitentext veröffentlichen)

- Die Aufbewahrung von 13 Monaten (Standard von `sayamaapps.kizami.retentionMonths`) ist ein technischer Standard, keine rechtliche
  Begründung; die notwendige Dauer muss der Betreiber festlegen.
- Neuaufbau der Datei (seit 3.1.0, ohne Cronjob): höchstens einmal je Tag
  (Europe/Berlin) und erst nach der Antwort an den Browser. Unter PHP-FPM und
  LiteSpeed schließt PHP die Antwort vorher ab (`fastcgi_finish_request()`,
  `litespeed_finish_request()`); dann übernimmt der erste erfasste Aufruf oder
  Bot-Aufruf des Tages. Unter cgi-fcgi und mod_php kann PHP das nicht; dort
  übernimmt der Aufruf eines erkannten Bots (auf ihn wartet niemand). Ein
  veralteter Browser wird nicht gezählt, nimmt diesen Weg aber nicht: ihn kann
  ein Mensch benutzen. Liegt der letzte gelungene Neuaufbau mehr als 48
  Stunden zurück, übernimmt der nächste erfasste Aufruf am Ende seiner
  Anfrage; dieser Besucher wartet dann einmal die Dauer des Neuaufbaus
  (gemessen rund 0,13 s für eine Datei von 20 MB auf normaler Platte, auf
  langsamer Platte mehrere Sekunden). Der Neuaufbau hält währenddessen die
  Schreibsperre. Solange die Datenbank gesperrt ist, etwa während eines
  Neuaufbaus, wartet ein Aufruf (Seitenaufruf, Klick, Aktion) wie bisher bis
  zu 5 Sekunden je Sperre; in seltenen Fällen, wenn eine Sperre frei und
  wieder genommen wird, während der Aufruf geschrieben wird, addieren sich
  diese Wartezeiten. Ist die Datenbank dann noch gesperrt, kommt der Aufruf in
  eine Zwischendatei neben der Datenbank (`storage/kizami/spool.jsonl`,
  dieselben Angaben wie in der Datenbank, nichts weiter) und wird bei den
  nächsten gelungenen Schreibvorgängen und am Ende des Neuaufbaus gespeichert,
  jeweils höchstens 1.000 Zeilen; ein Klick, der einen Seitenaufruf desselben
  Besuchs braucht, wird erst dann entschieden. Die Zwischendatei fasst
  höchstens 5 MB; solange sie voll ist, gehen weitere Aufrufe verloren, und
  das Error-Log meldet das, wenn sie voll wird, und danach höchstens einmal am
  Tag. Kann die Zwischendatei nicht geschrieben werden, geht der Aufruf
  ebenfalls verloren; das Error-Log enthält dann `database is locked`. Die
  Zwischendatei liegt wie die Datenbank außerhalb des Web-Verzeichnisses und
  ist nur für ihren Eigentümer lesbar; sie wird geleert, sobald sie bis zum
  Ende eingelesen ist, Zeilen älter als die Aufbewahrungsfrist werden beim
  Einlesen verworfen. Wie weit sie eingelesen ist, steht in der Datenbank, so
  wird kein Aufruf doppelt gezählt. Snapshots enthalten sie nicht; aus
  Dateisicherungen wie `secret.txt` ausschließen und zusammen mit der
  Datenbank löschen, wenn die Erfassung dauerhaft abgeschaltet wird. Ein
  Neuaufbau braucht vorübergehend freien Speicherplatz bis zur doppelten
  Dateigröße: eine Kopie im temporären Verzeichnis (oft eine andere Partition)
  und das Journal oder WAL neben der Datei. Fehler stehen im Error-Log
  (`Kizami: rebuild …`); der nächste Versuch folgt spätestens am nächsten Tag.
  Scheitern die Versuche seit mehr als 48 Stunden, zeigt das Dashboard eine
  Warnung.
- `php site/plugins/kizami/maintenance.php compact /absolut/storage/kizami`
  ist für den Neuaufbau nicht mehr nötig, kann aber zusätzlich als Cronjob
  laufen; Exitcode 1 und stderr sind ein
  Wartungsfehler. Ist `sayamaapps.kizami.retentionMonths` gesetzt, muss derselbe Wert als
  `--retention-months=N` angegeben werden, sonst gelten 13 Monate.
  Der Job löscht außerdem einen veralteten Tagesschlüssel (er erzeugt keinen,
  damit keine Datei mit dem Cron-Benutzer als Eigentümer entsteht) und meldet
  `stale daily key removed` bzw. `daily key current`; er ist dann nötig, wenn
  die Seite Tage ohne Aufruf haben kann. Wird die Erfassung
  dauerhaft abgeschaltet, `storage/kizami/secret.txt` und
  `storage/kizami/spool.jsonl` löschen.
- Zusammenfassung und Entfernung der Einzelereignisse erfolgen gemeinsam in
  einer Transaktion. Bei Fehlern bleiben die Einzelereignisse erhalten. Die
  Langzeitansicht kombiniert aktuelle Details und Tageszahlen ohne Doppelzählung.
  Alte Detailaufschlüsselungen nach Seite/Quelle/Klickziel werden nicht archiviert.
- Nachträgliche Rohdatenimporte in bereits zusammengefasste Tage werden nicht
  unterstützt. Ein Konflikt erhält beide Datenstände und stoppt die Bereinigung;
  auch neue Erfassungen können dann bis zur Korrektur dieses Imports ausfallen.
  Normale Webaufrufe verwenden den aktuellen Zeitpunkt und lösen das nicht aus.
- `php site/plugins/kizami/maintenance.php snapshot /absolut/storage/kizami /sicherungen/NEU.sqlite`
  bereinigt zuerst und erstellt einen konsistenten SQLite-Snapshot über die Backup-API,
  auch bei WAL. Bestehende Ziele werden abgewiesen. Das Geheimnis wird nicht kopiert.
- `php site/plugins/kizami/bin/kizami-snapshot /absolut/storage/kizami /sicherungen/NEU.sqlite`
  erstellt denselben Snapshot ohne vorheriges Zusammenfassen. Für Hoster ohne Shell
  liefert `GET /k/snapshot` (Schalter `sayamaapps.kizami.snapshot`, Basic Auth, nur HTTPS) die
  Kopie aus. Der Snapshot wird ohne freie Seiten neu aufgebaut. Sie enthält alle Einzelereignisse — Empfänger und Aufbewahrung der
  Sicherungen gehören in den Datenschutztext.
- `storage/kizami/secret.txt` und temporäre Geheimnisdateien aus allgemeinen
  Dateisicherungen ausschließen. Hoster-Snapshots können Schlüssel trotzdem enthalten;
  ihre Reichweite und Aufbewahrungszeit müssen mit dem Hoster geklärt werden.
- Vollständige Anonymität folgt weder aus einem Hash noch aus Schlüsselrotation:
  Zusatzwissen, URL-Inhalte und Backups sind zu berücksichtigen. Keine Namen,
  E-Mail-Adressen oder anderen persönlichen Angaben in UTM-Werten/Seitenpfaden verwenden.
- Weitere eigene Domains können über `sayamaapps.kizami.ownHosts` als Array angegeben
  werden, damit Verweise zwischen Domain-Aliasen nicht als externe Herkunft erscheinen.
- Server-Logs, Hosting-Auftragsverarbeitung, gesetzliche Rechtsgrundlage und
  Widerspruchsverfahren müssen im projektspezifischen Datenschutztext erklärt werden.

## Optionale Interaktionen konfigurieren

- `sayamaapps.kizami.contactTargets`: explizite Teilmenge der Weiterleitungsnamen für die
  Herkunftsauswertung. Ohne Konfiguration erscheint dieser Block nicht.
- `sayamaapps.kizami.positionNames`: erlaubte Positionskennungen mit lesbaren Namen.
  `$page->kizamiRedirectLink('anruf', 'tel:+490000', 'header')` ergänzt die Position.
  Ohne bekannte Kennung bleibt die Position leer; der Link funktioniert weiter.
- `sayamaapps.kizami.images`: erlaubte Bildkennungen mit lesbaren Namen. Ohne diese
  Konfiguration sendet die Galerie keine Bildmeldungen. Am Bildlink steht
  `data-kizami-image="hof"`, passend etwa zu `['hof' => 'Hofansicht']`.
  Das Snippet `bildschau` übergibt den eigenen Endpunkt an `bildschau.js` nur bei
  aktivem Modul mit konfigurierten Bildern. Fremde Origins, unbekannte Kennungen
  und nicht vorhandene Herkunftsseiten werden abgewiesen. DNT/GPC und Botfilter
  gelten ebenso wie für Seitenaufrufe. Browser-Speicher wird nicht verwendet.
- Ein atomarer `INSERT … SELECT … WHERE NOT EXISTS` dedupliziert Bildöffnungen
  auch bei parallelen Requests. Bildöffnungen sind Ereignisse vom Typ `image`. SQL bleibt mit SQLite 3.7.17 kompatibel.
- `sayamaapps.kizami.events`: erlaubte Aktionen mit Namen und dem Schalter `detail` für
  Zusatzangaben. Ohne diese Konfiguration gibt es weder `/k/event` noch
  `/k/event.js`, und das Snippet `kizami/events` gibt nichts aus. Fremde Origins,
  unbekannte Aktionen, nicht vorhandene Seiten und Anfragen über 2 KB werden
  abgewiesen; DNT/GPC und Botfilter gelten wie für Seitenaufrufe. Zusatzangaben
  nur für Aktionen mit `detail => true` freigeben und keine personenbezogenen
  Angaben (Namen, E-Mail-Adressen) hineinschreiben. Aktionen liegen in der
  Tabelle `kizami_custom_events`, sind in Sicherungen enthalten und zählen weder
  in die Besuche noch in die Aktionsquote oder Langzeitsummen.
  `$page->kizamiEvent('schluessel')` zählt eine konfigurierte Aktion ohne
  Skript aus einer Route oder einem Template, mit denselben Regeln außer der
  Origin-Prüfung.
- Herkunft und Einstiegsseiten funktionieren mit vorhandenen Seiten-/Klickdaten.
  Linkpositionen und Bildöffnungen beginnen erst mit ihrer Einführung. Bilddaten
  zählen weder in die Besuche noch in Kontakt-/Aktionsquoten oder Langzeitsummen.
