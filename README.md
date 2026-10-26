# Module Watchdog LCN

**Module Watchdog LCN (MWLCN)** centrally monitors multiple LCN module instances using a single IP-Symcon instance.

The module treats normal LCN activity as a life sign. If a configured LCN module does not report within the configured check interval, the watchdog sends an active probe. Probes are serialized with a configurable minimum spacing to avoid command bursts on the LCN bus.

## Requirements

- IP-Symcon 8.1 or newer
- LCN splitter instance in IP-Symcon
- LCN modules available as IP-Symcon instances

## How it works

The watchdog maintains an internal state for every configured LCN module. A received life sign sets the module to `Alive`, resets the retry counter and postpones the next check by the configured check interval.

If no life sign is received, a probe is sent when the interval expires. If the module does not respond within the probe timeout, the probe is repeated up to the configured maximum number of tries. If the final probe also remains unanswered within the probe timeout, the module is set to `Offline`. With the default settings, an outage is detected about 10½ minutes after the last life sign. Offline modules continue to be probed once per check interval and immediately return to `Alive` when a new life sign is received.

Status requests are preferred as probes because they are answered most reliably on the LCN bus. Measured values, light scenes or thresholds are only requested if no suitable instance exists.

Active probes are centrally serialized. At least the configured minimum spacing is kept between two probes. After an IP-Symcon restart or when the configuration is saved, all modules are checked one after another within a few seconds so that their state is known quickly. The minimum spacing is kept here as well, so no command bursts occur. Modules that report on their own before that receive no probe.

If the watchdog cannot find a variable for an LCN module that can be used to detect life signs and send probes, the module receives the `NoProbe` state and a warning is written to the message log. The watchdog searches again at every check interval, so instances created later are picked up automatically.

Disabled modules remain in the configuration and are published with the `Disabled` state, but no probes are sent to them.

## Configuration

| Setting | Description | Default |
| --- | --- | ---: |
| Check interval | Time without a life sign before a probe is sent (max. 86400 s) | 600 s |
| Probe timeout | Time to wait for the answer to a probe before it is repeated; must be shorter than the check interval | 10 s |
| Minimum time between probes | Minimum spacing between two actively sent LCN requests | 1000 ms |
| Maximum tries | Number of unanswered probes after which a module is considered `Offline` | 3 |
| Publish interval | Interval at which the current status is published again | 300 s |
| LCN modules | List of LCN module instances to monitor | – |
| Counter variables | Optional variables holding the number of modules per state | off |

Each LCN module instance may only be configured once. The **Active** switch can temporarily exclude a module from monitoring without removing it from the configuration. The **Variable** switch creates a dedicated status variable for the module.

## Status variable

The module creates a public string variable named `Status`. It contains a JSON object using the IP-Symcon InstanceID as key and the current state as value.

Example:

```json
{
    "12345": "Alive",
    "12351": "Offline",
    "12402": "Disabled",
    "12418": "Unknown",
    "12433": "NoProbe"
}
```

Possible states:

- `Unknown` – no life sign has been received since startup or activation and monitoring has not yet completed.
- `Alive` – the LCN module has produced a life sign.
- `Offline` – the configured number of probes produced no life sign.
- `Disabled` – the module is disabled in the configuration.
- `NoProbe` – no monitorable variable was found for the LCN module; the module cannot be monitored.

The status variable is written immediately when a state changes and additionally at the configured publish interval. IP-Symcon events can therefore react either to **OnChange** or **OnUpdate**.

## Optional variables

Since variables count towards the IP-Symcon license model, all additional variables are optional.

- **Counter variables:** For each state, an integer variable holding the number of modules in that state can be enabled (e.g. `Count Offline`).
- **Variable per LCN module:** A boolean variable named after the LCN module. It is `true` for `Alive` and `false` for `Offline`. For all other states it keeps its last value.

The optional variables are written like the status variable, on changes and at the publish interval.

If an option is switched off or a module is removed from the list, the corresponding variable is **not deleted**. It remains below the instance but is no longer managed by the module (its ident is removed) and is no longer updated. This way, events or other references to the variable are not lost. Variables that are no longer needed have to be deleted manually. If the option is switched on again later, the module creates a new variable.

## Functions

### MWLCN_GetModuleStatus

Returns technical details for one configured LCN module.

```php
$status = MWLCN_GetModuleStatus($InstanceID, $LCNInstanceID);
```

Return value:

```php
[
    'Status'      => 'Alive',
    'LastSeen'    => 1788100000,
    'LastTrigger' => 1788099400,
    'Try'         => 0,
    'NextDue'     => 1788100600
]
```

`LastSeen`, `LastTrigger` and `NextDue` are Unix timestamps. `Try` is the number of probes that have remained unanswered since the last life sign. An empty array is returned if the specified LCN instance is not monitored.

`NextDue` is the time at which the module becomes due for processing again. Whenever a life sign is received, `NextDue` is set to the current time plus the configured check interval. If another life sign is received before `NextDue`, the interval starts again. If an active module reaches `NextDue` without reporting in the meantime, a probe is sent and `NextDue` is set to the current time plus the probe timeout (plus the check interval for offline modules). The actual transmission may be delayed by the configured minimum spacing between two probes.

For disabled modules, `NextDue` is maintained as well. When the time is reached, the module is only moved within the queue; no probe is sent and the retry counter is not increased.

### MWLCN_GetAllModuleStatus

Returns technical details for all configured LCN modules. The InstanceID is used as the array key.

```php
$status = MWLCN_GetAllModuleStatus($InstanceID);
```

## License

MIT License
