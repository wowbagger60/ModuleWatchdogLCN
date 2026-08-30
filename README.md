# Module Watchdog LCN

**Module Watchdog LCN (MWLCN)** centrally monitors multiple LCN module instances using a single IP-Symcon instance.

The module treats normal LCN activity as a life sign. If a configured LCN module does not report within the configured check interval, the watchdog sends an active probe. Probes are serialized with a configurable minimum spacing to avoid command bursts on the LCN bus.

## Requirements

- IP-Symcon 8.1 or newer
- LCN splitter instance in IP-Symcon
- LCN modules available as IP-Symcon instances

## How it works

The watchdog maintains an internal state for every configured LCN module. A received life sign sets the module to `Alive`, resets the retry counter and postpones the next check by the configured check interval.

If no life sign is received, a probe is sent when the interval expires. If the module still does not respond, the probe is repeated up to the configured maximum number of tries. After the final unsuccessful attempt, the module is set to `Offline`. Offline modules continue to be probed periodically and immediately return to `Alive` when a new life sign is received.

Active probes are centrally serialized. At least the configured minimum spacing is kept between two probes. This prevents command bursts even after an IP-Symcon restart or when several modules become due at the same time.

Disabled modules remain in the configuration and are published with the `Disabled` state, but no probes are sent to them.

## Configuration

| Setting | Description | Default |
| --- | --- | ---: |
| Check interval | Time without a life sign before a probe is sent | 600 s |
| Minimum time between probes | Minimum spacing between two actively sent LCN requests | 1000 ms |
| Maximum tries | Number of probes before a module is considered `Offline` | 3 |
| Publish interval | Interval at which the current status is published again | 300 s |
| LCN modules | List of LCN module instances to monitor | – |

Each LCN module instance may only be configured once. The **Active** switch can temporarily exclude a module from monitoring without removing it from the configuration.

## Status variable

The module creates a public string variable named `Status`. It contains a JSON object using the IP-Symcon InstanceID as key and the current state as value.

Example:

```json
{
    "12345": "Alive",
    "12351": "Offline",
    "12402": "Disabled",
    "12418": "Unknown"
}
```

Possible states:

- `Unknown` – no life sign has been received since startup or activation and monitoring has not yet completed.
- `Alive` – the LCN module has produced a life sign.
- `Offline` – the configured number of probes produced no life sign.
- `Disabled` – the module is disabled in the configuration.

The status variable is written immediately when a state changes and additionally at the configured publish interval. IP-Symcon events can therefore react either to **OnChange** or **OnUpdate**.

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

`LastSeen`, `LastTrigger` and `NextDue` are Unix timestamps. An empty array is returned if the specified LCN instance is not monitored.

`NextDue` is the time at which the module becomes due for processing again. Whenever a life sign is received or the module is processed, `NextDue` is set to the current time plus the configured check interval. If another life sign is received before `NextDue`, the interval starts again. If an active module reaches `NextDue` without reporting in the meantime, a probe is sent. The actual transmission may be delayed by the configured minimum spacing between two probes.

For disabled modules, `NextDue` is maintained as well. When the time is reached, the module is only moved within the queue; no probe is sent and the retry counter is not increased.

### MWLCN_GetAllModuleStatus

Returns technical details for all configured LCN modules. The InstanceID is used as the array key.

```php
$status = MWLCN_GetAllModuleStatus($InstanceID);
```

## License

MIT License
