# Module Watchdog LCN

**Module Watchdog LCN (MWLCN)** überwacht mehrere LCN-Modulinstanzen zentral mit einer einzigen IP-Symcon-Instanz.

Das Modul wertet normale LCN-Aktivität als Lebenszeichen aus. Meldet sich ein konfiguriertes LCN-Modul innerhalb des eingestellten Abfrage-Intervalls nicht, sendet der Watchdog eine aktive Probe. Die Probes werden mit einem konfigurierbaren Mindestabstand nacheinander gesendet, um Command-Bursts auf dem LCN-Bus zu vermeiden.

## Voraussetzungen

- IP-Symcon ab Version 8.1
- LCN-Splitter-Instanz in IP-Symcon
- LCN-Module als IP-Symcon-Instanzen

## Funktionsweise

Für jedes konfigurierte LCN-Modul verwaltet der Watchdog einen internen Status. Ein empfangenes Lebenszeichen setzt das Modul auf `Alive`, setzt den Versuchszähler zurück und verschiebt den nächsten Prüfzeitpunkt um das konfigurierte Abfrage-Intervall.

Bleibt ein Lebenszeichen aus, wird nach Ablauf des Intervalls eine Probe gesendet. Antwortet das Modul weiterhin nicht, wird die Probe bis zur konfigurierten maximalen Anzahl Versuche wiederholt. Nach dem letzten erfolglosen Versuch wird das Modul auf `Offline` gesetzt. Offline-Module werden weiterhin periodisch geprüft und wechseln bei einem neuen Lebenszeichen sofort wieder auf `Alive`.

Die aktiven Probes werden zentral serialisiert. Zwischen zwei Probes liegt mindestens der konfigurierte Mindestabstand. Dadurch entstehen auch bei einem IP-Symcon-Neustart oder beim gleichzeitigen Ausbleiben mehrerer Module keine Command-Bursts.

Deaktivierte Module bleiben in der Konfiguration und werden mit dem Status `Disabled` veröffentlicht, erhalten jedoch keine Probes.

## Konfiguration

| Einstellung | Beschreibung | Standard |
| --- | --- | ---: |
| Abfrage-Intervall | Zeit ohne Lebenszeichen, nach der eine Probe ausgelöst wird | 600 s |
| Minimale Zeit zwischen zwei Probes | Mindestabstand zwischen zwei aktiv gesendeten LCN-Abfragen | 1000 ms |
| Maximale Anzahl Versuche | Anzahl der Probes, bevor ein Modul als `Offline` gilt | 3 |
| Veröffentlichungsintervall | Zeitintervall, in dem der aktuelle Status erneut veröffentlicht wird | 300 s |
| LCN-Module | Liste der zu überwachenden LCN-Modulinstanzen | – |

Jede LCN-Modulinstanz darf nur einmal konfiguriert werden. Über den Schalter **Aktiv** kann ein Modul temporär von der Überwachung ausgeschlossen werden, ohne den Eintrag zu löschen.

## Statusvariable

Das Modul erzeugt eine öffentliche Stringvariable `Status`. Sie enthält ein JSON-Objekt mit der IP-Symcon-InstanceID als Schlüssel und dem aktuellen Zustand als Wert.

Beispiel:

```json
{
    "12345": "Alive",
    "12351": "Offline",
    "12402": "Disabled",
    "12418": "Unknown"
}
```

Mögliche Zustände:

- `Unknown` – seit Start oder Aktivierung wurde noch kein Lebenszeichen empfangen und die Überwachung ist noch nicht abgeschlossen.
- `Alive` – das LCN-Modul hat ein Lebenszeichen geliefert.
- `Offline` – die konfigurierte Anzahl Probes blieb ohne Lebenszeichen.
- `Disabled` – das Modul ist in der Konfiguration deaktiviert.

Die Statusvariable wird bei Statusänderungen sofort und zusätzlich im konfigurierten Veröffentlichungsintervall geschrieben. Dadurch können IP-Symcon-Ereignisse wahlweise auf **OnChange** oder **OnUpdate** reagieren.

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

`LastSeen`, `LastTrigger` und `NextDue` sind Unix-Timestamps. Wird die angegebene LCN-Instanz nicht überwacht, wird ein leeres Array zurückgegeben.

`NextDue` bezeichnet den Zeitpunkt, zu dem das Modul das nächste Mal zur Verarbeitung fällig wird. Bei jedem empfangenen Lebenszeichen und nach jeder Verarbeitung wird `NextDue` auf den aktuellen Zeitpunkt plus das konfigurierte Abfrage-Intervall gesetzt. Wird vor `NextDue` erneut ein Lebenszeichen empfangen, beginnt das Intervall von vorne. Erreicht ein aktives Modul `NextDue`, ohne sich zwischenzeitlich gemeldet zu haben, wird eine Probe gesendet. Der tatsächliche Versand kann sich durch den konfigurierten Mindestabstand zwischen zwei Probes verzögern.

Bei deaktivierten Modulen wird `NextDue` ebenfalls weitergeführt. Beim Erreichen des Zeitpunkts wird das Modul lediglich innerhalb der Queue weitergeschoben; es wird keine Probe gesendet und der Versuchszähler wird nicht erhöht.

### MWLCN_GetAllModuleStatus

Liefert die technischen Detaildaten aller konfigurierten LCN-Module. Die InstanceID ist jeweils der Array-Key.

```php
$status = MWLCN_GetAllModuleStatus($InstanceID);
```

## Lizenz

MIT License
