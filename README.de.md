# Module Watchdog LCN

**Module Watchdog LCN (MWLCN)** überwacht mehrere LCN-Modulinstanzen zentral mit einer einzigen IP-Symcon-Instanz.

Das Modul wertet normale LCN-Aktivität als Lebenszeichen aus. Meldet sich ein konfiguriertes LCN-Modul innerhalb des eingestellten Abfrage-Intervalls nicht, sendet der Watchdog eine aktive Probe. Die Probes werden mit einem konfigurierbaren Mindestabstand nacheinander gesendet, um Command-Bursts auf dem LCN-Bus zu vermeiden.

## Voraussetzungen

- IP-Symcon ab Version 8.1
- LCN-Splitter-Instanz in IP-Symcon
- LCN-Module als IP-Symcon-Instanzen

## Funktionsweise

Für jedes konfigurierte LCN-Modul verwaltet der Watchdog einen internen Status. Ein empfangenes Lebenszeichen setzt das Modul auf `Alive`, setzt den Versuchszähler zurück und verschiebt den nächsten Prüfzeitpunkt um das konfigurierte Abfrage-Intervall.

Bleibt ein Lebenszeichen aus, wird nach Ablauf des Intervalls eine Probe gesendet. Antwortet das Modul nicht innerhalb des Antwort-Timeouts, wird die Probe bis zur konfigurierten maximalen Anzahl Versuche wiederholt. Bleibt auch die letzte Probe innerhalb des Antwort-Timeouts unbeantwortet, wird das Modul auf `Offline` gesetzt. Mit den Standardwerten wird ein Ausfall so etwa 10½ Minuten nach dem letzten Lebenszeichen erkannt. Offline-Module werden weiterhin einmal pro Abfrage-Intervall geprüft und wechseln bei einem neuen Lebenszeichen sofort wieder auf `Alive`.

Als Probe wird bevorzugt eine Statusabfrage verwendet, da diese in LCN am zuverlässigsten beantwortet wird. Nur wenn keine passende Instanz vorhanden ist, werden Messwerte, Lichtszenen oder Schwellwerte abgefragt.

Die aktiven Probes werden zentral serialisiert. Zwischen zwei Probes liegt mindestens der konfigurierte Mindestabstand. Nach einem IP-Symcon-Neustart oder dem Speichern der Konfiguration werden alle Module nach wenigen Sekunden nacheinander geprüft, damit ihr Status rasch bekannt ist. Auch dabei wird der Mindestabstand eingehalten, sodass keine Command-Bursts entstehen. Module, die sich vorher von selbst melden, erhalten keine Probe.

Findet der Watchdog zu einem LCN-Modul keine Variable, über die Lebenszeichen erkannt und Probes gesendet werden können, erhält das Modul den Status `NoProbe`, und im Meldungsprotokoll erscheint eine Warnung. Der Watchdog sucht bei jedem Abfrage-Intervall erneut, sodass später angelegte Instanzen automatisch berücksichtigt werden.

Deaktivierte Module bleiben in der Konfiguration und werden mit dem Status `Disabled` veröffentlicht, erhalten jedoch keine Probes.

## Konfiguration

| Einstellung | Beschreibung | Standard |
| --- | --- | ---: |
| Abfrage-Intervall | Zeit ohne Lebenszeichen, nach der eine Probe ausgelöst wird (max. 86400 s) | 600 s |
| Antwort-Timeout | Wartezeit auf die Antwort einer Probe, bevor sie wiederholt wird; muss kürzer als das Abfrage-Intervall sein | 10 s |
| Minimale Zeit zwischen zwei Probes | Mindestabstand zwischen zwei aktiv gesendeten LCN-Abfragen | 1000 ms |
| Maximale Anzahl Versuche | Anzahl unbeantworteter Probes, nach denen ein Modul als `Offline` gilt | 3 |
| Veröffentlichungsintervall | Zeitintervall, in dem der aktuelle Status erneut veröffentlicht wird | 300 s |
| LCN-Module | Liste der zu überwachenden LCN-Modulinstanzen | – |
| Zähler-Variablen | Optionale Variablen mit der Anzahl Module je Status | aus |

Jede LCN-Modulinstanz darf nur einmal konfiguriert werden. Über den Schalter **Aktiv** kann ein Modul temporär von der Überwachung ausgeschlossen werden, ohne den Eintrag zu löschen. Über den Schalter **Variable** wird für das Modul eine eigene Statusvariable angelegt.

## Statusvariable

Das Modul erzeugt eine öffentliche Stringvariable `Status`. Sie enthält ein JSON-Objekt mit der IP-Symcon-InstanceID als Schlüssel und dem aktuellen Zustand als Wert.

Beispiel:

```json
{
    "12345": "Alive",
    "12351": "Offline",
    "12402": "Disabled",
    "12418": "Unknown",
    "12433": "NoProbe"
}
```

Mögliche Zustände:

- `Unknown` – seit Start oder Aktivierung wurde noch kein Lebenszeichen empfangen und die Überwachung ist noch nicht abgeschlossen.
- `Alive` – das LCN-Modul hat ein Lebenszeichen geliefert.
- `Offline` – die konfigurierte Anzahl Probes blieb ohne Lebenszeichen.
- `Disabled` – das Modul ist in der Konfiguration deaktiviert.
- `NoProbe` – zum LCN-Modul wurde keine überwachbare Variable gefunden; das Modul kann nicht überwacht werden.

Die Statusvariable wird bei Statusänderungen sofort und zusätzlich im konfigurierten Veröffentlichungsintervall geschrieben. Dadurch können IP-Symcon-Ereignisse wahlweise auf **OnChange** oder **OnUpdate** reagieren.

## Optionale Variablen

Da Variablen im Lizenzmodell von IP-Symcon zählen, sind alle weiteren Variablen optional.

- **Zähler-Variablen:** Für jeden Status kann eine Integer-Variable mit der Anzahl Module in diesem Status aktiviert werden (z. B. `Anzahl Offline`).
- **Variable pro LCN-Modul:** Eine Boolean-Variable mit dem Namen des LCN-Moduls. Sie ist `true` bei `Alive` und `false` bei `Offline`. Bei den übrigen Zuständen behält sie ihren letzten Wert.

Die optionalen Variablen werden wie die Statusvariable bei Änderungen und im Veröffentlichungsintervall geschrieben.

Wird eine Option abgeschaltet oder ein Modul aus der Liste entfernt, wird die zugehörige Variable **nicht gelöscht**. Sie bleibt unter der Instanz bestehen, wird aber vom Modul nicht mehr verwaltet (ihr Ident wird entfernt) und nicht mehr aktualisiert. So gehen Ereignisse oder andere Verweise auf die Variable nicht verloren. Nicht mehr benötigte Variablen müssen von Hand gelöscht werden. Wird die Option später wieder eingeschaltet, legt das Modul eine neue Variable an.

## Funktionen

### MWLCN_GetModuleStatus

Liefert die technischen Detaildaten eines konfigurierten LCN-Moduls.

```php
$status = MWLCN_GetModuleStatus($InstanceID, $LCNInstanceID);
```

Rückgabe:

```php
[
    'Status'      => 'Alive',
    'LastSeen'    => 1788100000,
    'LastTrigger' => 1788099400,
    'Try'         => 0,
    'NextDue'     => 1788100600
]
```

`LastSeen`, `LastTrigger` und `NextDue` sind Unix-Timestamps. `Try` ist die Anzahl Probes, die seit dem letzten Lebenszeichen unbeantwortet geblieben sind. Wird die angegebene LCN-Instanz nicht überwacht, wird ein leeres Array zurückgegeben.

`NextDue` bezeichnet den Zeitpunkt, zu dem das Modul das nächste Mal zur Verarbeitung fällig wird. Bei jedem empfangenen Lebenszeichen wird `NextDue` auf den aktuellen Zeitpunkt plus das konfigurierte Abfrage-Intervall gesetzt. Wird vor `NextDue` erneut ein Lebenszeichen empfangen, beginnt das Intervall von vorne. Erreicht ein aktives Modul `NextDue`, ohne sich zwischenzeitlich gemeldet zu haben, wird eine Probe gesendet und `NextDue` auf den aktuellen Zeitpunkt plus das Antwort-Timeout gesetzt (bei Offline-Modulen plus das Abfrage-Intervall). Der tatsächliche Versand kann sich durch den konfigurierten Mindestabstand zwischen zwei Probes verzögern.

Bei deaktivierten Modulen wird `NextDue` ebenfalls weitergeführt. Beim Erreichen des Zeitpunkts wird das Modul lediglich innerhalb der Queue weitergeschoben; es wird keine Probe gesendet und der Versuchszähler wird nicht erhöht.

### MWLCN_GetAllModuleStatus

Liefert die technischen Detaildaten aller konfigurierten LCN-Module. Die InstanceID ist jeweils der Array-Key.

```php
$status = MWLCN_GetAllModuleStatus($InstanceID);
```

## Lizenz

MIT License
