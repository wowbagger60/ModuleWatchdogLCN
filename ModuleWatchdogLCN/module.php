<?php declare(strict_types=1);

class ModuleWatchdogLCN extends IPSModuleStrict
{
    private const LCN_MODULE_ID = '{0E31FED6-E465-4621-95D4-AAF2683C41EC}';

    private const DEVICE_TYPES = [
        '{2D871359-14D8-493F-9B01-26432E3A710F}' => [
            1 => ['Status', 'Intensity']
        ],
        '{0102BDC9-3B85-4A11-968D-7D314DA07C06}' => [
            0 => [
                'R1Value', 'R2Value', 'TValue', 'TShift', 'R1Target', 'R2Target',
                'A1Value', 'A2Value', 'A3Value', 'A4Value', 'A5Value', 'A6Value',
                'A7Value', 'A8Value', 'A9Value', 'A10Value', 'A11Value', 'A12Value',
                'S1Target', 'S2Target', 'C1Counter', 'C2Counter', 'C3Counter', 'C4Counter'
            ]
        ],
        '{A26E7E5A-A7C5-4063-8BE0-ED8BB26F8411}' => [
            2 => [
                'Light1', 'Light2', 'Light3', 'Light4', 'Light5', 'Light6', 'Light7', 'Light8',
                'Light9', 'Light10', 'Light11', 'Light12', 'Light13', 'Light14', 'Light15', 'Light16'
            ],
            3 => [
                'Threshold1', 'Threshold2', 'Threshold3', 'Threshold4', 'Threshold5', 'Threshold6',
                'Threshold11', 'Threshold12', 'Threshold13', 'Threshold14', 'Threshold15',
                'Threshold21', 'Threshold22', 'Threshold23', 'Threshold24', 'Threshold25',
                'Threshold31', 'Threshold32', 'Threshold33', 'Threshold34', 'Threshold35',
                'Threshold41', 'Threshold42', 'Threshold43', 'Threshold44', 'Threshold45'
            ]
        ]
    ];

    private const STATUS_UNKNOWN = 'Unknown';
    private const STATUS_ALIVE = 'Alive';
    private const STATUS_OFFLINE = 'Offline';
    private const STATUS_DISABLED = 'Disabled';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('Interval', 600);
        $this->RegisterPropertyInteger('CommandInterval', 1000);
        $this->RegisterPropertyInteger('MaxTries', 3);
        $this->RegisterPropertyInteger('PublishInterval', 300);
        $this->RegisterPropertyString('Modules', '[]');

        if ($this->RegisterVariableString('Status', $this->Translate('Status'), [], 0)) {
            $this->SetValue('Status', '{}');
        }

        $this->RegisterTimer('Scheduler', 0, 'MWLCN_SchedulerTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('Publisher', 0, 'MWLCN_PublishTimer($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('Scheduler', 0);
        $this->SetTimerInterval('Publisher', 0);
        $this->clearMessagesAndReferences();
        $this->SetBuffer('Queue', '[]');
        $this->SetBuffer('VariableMap', '{}');
        $this->SetBuffer('LastProbeAt', '0');

        if (!$this->validateConfiguration()) {
            $this->publishStatus();
            return;
        }

        $this->buildRuntimeState();
        $this->SetStatus(IS_ACTIVE);
        $this->publishStatus();
        $this->scheduleNext();
        $this->SetTimerInterval('Publisher', $this->ReadPropertyInteger('PublishInterval') * 1000);
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'Interval',
                    'caption' => 'Check interval',
                    'suffix'  => 'seconds',
                    'minimum' => 1
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'CommandInterval',
                    'caption' => 'Minimum time between probes',
                    'suffix'  => 'milliseconds',
                    'minimum' => 1,
                    'maximum' => 60000
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'MaxTries',
                    'caption' => 'Maximum tries',
                    'minimum' => 1
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'PublishInterval',
                    'caption' => 'Publish interval',
                    'suffix'  => 'seconds',
                    'minimum' => 1
                ],
                [
                    'type'        => 'List',
                    'name'        => 'Modules',
                    'caption'     => 'LCN modules',
                    'rowCount'    => 20,
                    'add'         => true,
                    'delete'      => true,
                    'changeOrder' => false,
                    'columns'     => [
                        [
                            'caption' => 'Active',
                            'name'    => 'Enabled',
                            'width'   => '80px',
                            'add'     => true,
                            'edit'    => ['type' => 'CheckBox']
                        ],
                        [
                            'caption' => 'LCN module',
                            'name'    => 'InstanceID',
                            'width'   => 'auto',
                            'add'     => 0,
                            'edit'    => [
                                'type'         => 'SelectInstance',
                                'validModules' => [self::LCN_MODULE_ID]
                            ]
                        ]
                    ],
                    'values' => []
                ]
            ],
            'status' => [
                ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => 'Watchdog active.'],
                ['code' => IS_EBASE + 1, 'icon' => 'error', 'caption' => 'Check interval must be greater than zero.'],
                ['code' => IS_EBASE + 2, 'icon' => 'error', 'caption' => 'Command interval must be greater than zero.'],
                ['code' => IS_EBASE + 3, 'icon' => 'error', 'caption' => 'Maximum tries must be greater than zero.'],
                ['code' => IS_EBASE + 4, 'icon' => 'error', 'caption' => 'Publish interval must be greater than zero.'],
                ['code' => IS_EBASE + 5, 'icon' => 'error', 'caption' => 'An LCN module must be selected in every row.'],
                ['code' => IS_EBASE + 6, 'icon' => 'error', 'caption' => 'An LCN module may only be configured once.']
            ]
        ];

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $map = $this->decodeObject($this->GetBuffer('VariableMap'));
            $moduleID = (int) ($map[(string) $SenderID] ?? 0);
            if ($moduleID > 0) {
                $this->handleLifeSign($moduleID);
            }
            return;
        }

        if ($Message === VM_DELETE) {
            $this->UnregisterMessage($SenderID, VM_UPDATE);
            $this->UnregisterMessage($SenderID, VM_DELETE);
            $this->rebuildWatchSources();
        }
    }

    public function SchedulerTimer(): void
    {
        $queue = $this->getQueue();
        if ($queue === []) {
            $this->SetTimerInterval('Scheduler', 0);
            return;
        }

        $statusChanged = false;

        while ($queue !== []) {
            $now = time();
            $head = $queue[0];

            if ((int) $head['NextDue'] > $now) {
                break;
            }

            array_shift($queue);

            if (!(bool) $head['Enabled']) {
                $head['Status'] = self::STATUS_DISABLED;
                $head['Try'] = 0;
                $head['NextDue'] = $now + $this->ReadPropertyInteger('Interval');
                $queue[] = $head;
                continue;
            }

            $moduleID = (int) $head['InstanceID'];
            if (!IPS_InstanceExists($moduleID)) {
                $statusChanged = true;
                continue;
            }

            if ((int) $head['Try'] >= $this->ReadPropertyInteger('MaxTries')) {
                if ((string) $head['Status'] !== self::STATUS_OFFLINE) {
                    $head['Status'] = self::STATUS_OFFLINE;
                    $statusChanged = true;
                }
                $head['Try'] = 0;
            }

            $lastProbeAt = (int) $this->GetBuffer('LastProbeAt');
            $commandReadyAt = $lastProbeAt + $this->commandIntervalSeconds();
            if ($now < $commandReadyAt) {
                array_unshift($queue, $head);
                break;
            }

            if ($this->sendProbe($head)) {
                $head['Try'] = (int) $head['Try'] + 1;
                $head['LastTrigger'] = $now;
                $this->SetBuffer('LastProbeAt', (string) $now);
            }

            $head['NextDue'] = $now + $this->ReadPropertyInteger('Interval');
            $queue[] = $head;
            break;
        }

        $this->setQueue($queue);
        if ($statusChanged) {
            $this->publishStatus();
        }
        $this->scheduleNext();
    }

    public function PublishTimer(): void
    {
        $this->publishStatus();
    }

    public function GetModuleStatus(int $LCNInstanceID): array
    {
        foreach ($this->getQueue() as $entry) {
            if ((int) $entry['InstanceID'] === $LCNInstanceID) {
                return [
                    'Status'      => (string) $entry['Status'],
                    'LastSeen'    => (int) $entry['LastSeen'],
                    'LastTrigger' => (int) $entry['LastTrigger'],
                    'Try'         => (int) $entry['Try'],
                    'NextDue'     => (int) $entry['NextDue']
                ];
            }
        }

        return [];
    }

    public function GetAllModuleStatus(): array
    {
        $result = [];
        foreach ($this->getQueue() as $entry) {
            $moduleID = (int) $entry['InstanceID'];
            $result[$moduleID] = [
                'Status'      => (string) $entry['Status'],
                'LastSeen'    => (int) $entry['LastSeen'],
                'LastTrigger' => (int) $entry['LastTrigger'],
                'Try'         => (int) $entry['Try'],
                'NextDue'     => (int) $entry['NextDue']
            ];
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    // -------------------------------------------------------------------------
    // Watchdog engine
    // -------------------------------------------------------------------------

    private function handleLifeSign(int $moduleID): void
    {
        $queue = $this->getQueue();
        $found = false;
        $statusChanged = false;
        $now = time();

        foreach ($queue as $index => $entry) {
            if ((int) $entry['InstanceID'] !== $moduleID) {
                continue;
            }

            $found = true;
            array_splice($queue, $index, 1);
            $oldStatus = (string) $entry['Status'];
            $entry['LastSeen'] = time();
            $entry['Try'] = 0;
            $entry['NextDue'] = $now + $this->ReadPropertyInteger('Interval');
            $entry['Status'] = (bool) $entry['Enabled'] ? self::STATUS_ALIVE : self::STATUS_DISABLED;
            $queue[] = $entry;
            $statusChanged = $oldStatus !== (string) $entry['Status'];
            break;
        }

        if (!$found) {
            return;
        }

        $this->setQueue($queue);
        if ($statusChanged) {
            $this->publishStatus();
        }
        $this->scheduleNext();
    }

    private function scheduleNext(): void
    {
        $queue = $this->getQueue();
        if ($queue === []) {
            $this->SetTimerInterval('Scheduler', 0);
            return;
        }

        $now = time();
        $head = $queue[0];
        $wakeAt = (int) $head['NextDue'];

        if ((bool) $head['Enabled']) {
            $lastProbeAt = (int) $this->GetBuffer('LastProbeAt');
            $wakeAt = max($wakeAt, $lastProbeAt + $this->commandIntervalSeconds());
        }

        $maxTimerSeconds = intdiv(PHP_INT_MAX, 1000);
        $delaySeconds = min(max(1, $wakeAt - $now), $maxTimerSeconds);
        $this->SetTimerInterval('Scheduler', $delaySeconds * 1000);
    }

    private function publishStatus(): void
    {
        $status = [];
        foreach ($this->getQueue() as $entry) {
            $status[(int) $entry['InstanceID']] = (string) $entry['Status'];
        }
        ksort($status, SORT_NUMERIC);
        $this->SetValue('Status', json_encode($status, JSON_THROW_ON_ERROR));
    }

    private function buildRuntimeState(): void
    {
        $queue = [];
        $variableMap = [];
        $now = time();
        $nextDue = $now + $this->ReadPropertyInteger('Interval');

        foreach ($this->decodeArray($this->ReadPropertyString('Modules')) as $row) {
            $moduleID = (int) ($row['InstanceID'] ?? 0);
            if ($moduleID <= 0 || !IPS_InstanceExists($moduleID)) {
                continue;
            }

            $enabled = (bool) ($row['Enabled'] ?? true);
            $watchVariables = $this->getWatchVariables($moduleID);
            $probe = $this->getProbe($moduleID, $watchVariables);

            $this->RegisterReference($moduleID);

            foreach ($watchVariables as $variable) {
                $variableID = (int) $variable['ID'];
                $this->RegisterMessage($variableID, VM_UPDATE);
                $this->RegisterMessage($variableID, VM_DELETE);
                $variableMap[(string) $variableID] = $moduleID;
            }

            $queue[] = [
                'InstanceID'  => $moduleID,
                'Enabled'     => $enabled,
                'Status'      => $enabled ? self::STATUS_UNKNOWN : self::STATUS_DISABLED,
                'LastSeen'    => 0,
                'LastTrigger' => 0,
                'Try'         => 0,
                'NextDue'     => $nextDue,
                'ProbeType'   => $probe['Type'],
                'ProbeTarget' => $probe['Target']
            ];
        }

        $this->setQueue($queue);
        $this->SetBuffer('VariableMap', json_encode($variableMap, JSON_THROW_ON_ERROR));
    }

    private function rebuildWatchSources(): void
    {
        foreach ($this->GetMessageList() as $senderID => $messages) {
            $id = (int) $senderID;
            if (in_array(VM_UPDATE, $messages, true)) {
                $this->UnregisterMessage($id, VM_UPDATE);
            }
            if (in_array(VM_DELETE, $messages, true)) {
                $this->UnregisterMessage($id, VM_DELETE);
            }
        }

        $queue = $this->getQueue();
        $variableMap = [];
        foreach ($queue as &$entry) {
            $moduleID = (int) $entry['InstanceID'];
            if (!IPS_InstanceExists($moduleID)) {
                continue;
            }
            $watchVariables = $this->getWatchVariables($moduleID);
            $probe = $this->getProbe($moduleID, $watchVariables);
            $entry['ProbeType'] = $probe['Type'];
            $entry['ProbeTarget'] = $probe['Target'];
            foreach ($watchVariables as $variable) {
                $variableID = (int) $variable['ID'];
                $this->RegisterMessage($variableID, VM_UPDATE);
                $this->RegisterMessage($variableID, VM_DELETE);
                $variableMap[(string) $variableID] = $moduleID;
            }
        }
        unset($entry);

        $this->setQueue($queue);
        $this->SetBuffer('VariableMap', json_encode($variableMap, JSON_THROW_ON_ERROR));
    }

    private function validateConfiguration(): bool
    {
        if ($this->ReadPropertyInteger('Interval') < 1) {
            $this->SetStatus(IS_EBASE + 1);
            return false;
        }
        if ($this->ReadPropertyInteger('CommandInterval') < 1 || $this->ReadPropertyInteger('CommandInterval') > 60000) {
            $this->SetStatus(IS_EBASE + 2);
            return false;
        }
        if ($this->ReadPropertyInteger('MaxTries') < 1) {
            $this->SetStatus(IS_EBASE + 3);
            return false;
        }
        if ($this->ReadPropertyInteger('PublishInterval') < 1) {
            $this->SetStatus(IS_EBASE + 4);
            return false;
        }

        $seen = [];
        foreach ($this->decodeArray($this->ReadPropertyString('Modules')) as $row) {
            $moduleID = (int) ($row['InstanceID'] ?? 0);
            if ($moduleID <= 0) {
                $this->SetStatus(IS_EBASE + 5);
                return false;
            }
            if (isset($seen[$moduleID])) {
                $this->SetStatus(IS_EBASE + 6);
                return false;
            }
            $seen[$moduleID] = true;
        }

        return true;
    }

    private function clearMessagesAndReferences(): void
    {
        foreach ($this->GetMessageList() as $senderID => $messages) {
            $id = (int) $senderID;
            foreach ($messages as $message) {
                $this->UnregisterMessage($id, (int) $message);
            }
        }
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }
    }

    private function getQueue(): array
    {
        return $this->decodeArray($this->GetBuffer('Queue'));
    }

    private function setQueue(array $queue): void
    {
        $this->SetBuffer('Queue', json_encode(array_values($queue), JSON_THROW_ON_ERROR));
    }

    private function commandIntervalSeconds(): int
    {
        return max(1, (int) ceil($this->ReadPropertyInteger('CommandInterval') / 1000));
    }

    private function decodeArray(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? array_values($data) : [];
    }

    private function decodeObject(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    // -------------------------------------------------------------------------
    // LCN adapter
    // -------------------------------------------------------------------------

    private function getWatchVariables(int $moduleID): array
    {
        $monitor = [];

        foreach (self::DEVICE_TYPES as $deviceModuleID => $deviceTypes) {
            foreach (IPS_GetInstanceListByModuleID($deviceModuleID) as $instanceID) {
                $instance = IPS_GetInstance($instanceID);
                if ((int) $instance['ConnectionID'] !== $moduleID) {
                    continue;
                }

                foreach (IPS_GetChildrenIDs($instanceID) as $variableID) {
                    $object = IPS_GetObject($variableID);
                    if ((int) $object['ObjectType'] !== 2 || (string) $object['ObjectIdent'] === '') {
                        continue;
                    }

                    foreach ($deviceTypes as $command => $idents) {
                        if (in_array((string) $object['ObjectIdent'], $idents, true)) {
                            $monitor[] = ['ID' => $variableID, 'Cmd' => (int) $command];
                            break;
                        }
                    }
                }
            }
        }

        return $monitor;
    }

    private function getProbe(int $moduleID, array $watchVariables): array
    {
        if ($watchVariables === []) {
            return ['Type' => -1, 'Target' => 0];
        }

        $variable = $watchVariables[0];
        $deviceID = IPS_GetParent((int) $variable['ID']);
        $type = (int) $variable['Cmd'];

        if ($type === 1) {
            return ['Type' => 1, 'Target' => $moduleID];
        }

        return ['Type' => $type, 'Target' => $deviceID];
    }

    private function sendProbe(array $entry): bool
    {
        $type = (int) $entry['ProbeType'];
        $target = (int) $entry['ProbeTarget'];
        if ($target <= 0) {
            return false;
        }

        switch ($type) {
            case 0:
                LCN_RequestRead($target);
                return true;
            case 1:
                LCN_RequestStatus($target);
                return true;
            case 2:
                LCN_RequestLights($target);
                return true;
            case 3:
                LCN_RequestThresholds($target);
                return true;
        }

        return false;
    }
}
