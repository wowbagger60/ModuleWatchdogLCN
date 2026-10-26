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

    // Status requests are the most reliable probes on the LCN bus
    private const PROBE_PRIORITY = [1, 0, 2, 3];

    private const STATUS_UNKNOWN = 'Unknown';
    private const STATUS_ALIVE = 'Alive';
    private const STATUS_OFFLINE = 'Offline';
    private const STATUS_DISABLED = 'Disabled';
    private const STATUS_NOPROBE = 'NoProbe';

    private const STATUSES = [
        self::STATUS_ALIVE,
        self::STATUS_OFFLINE,
        self::STATUS_UNKNOWN,
        self::STATUS_DISABLED,
        self::STATUS_NOPROBE
    ];

    private const MAX_INTERVAL = 86400;
    private const MAX_COMMAND_INTERVAL = 60000;
    private const MAX_TIMER_MILLISECONDS = 2147483647;
    private const STARTUP_DELAY = 10;
    private const LOCK_TIMEOUT = 5000;
    private const MODULE_VARIABLE_POSITION = 10;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('Interval', 600);
        $this->RegisterPropertyInteger('ProbeTimeout', 10);
        $this->RegisterPropertyInteger('CommandInterval', 1000);
        $this->RegisterPropertyInteger('MaxTries', 3);
        $this->RegisterPropertyInteger('PublishInterval', 300);
        $this->RegisterPropertyString('Modules', '[]');
        foreach (self::STATUSES as $status) {
            $this->RegisterPropertyBoolean('Counter' . $status, false);
        }

        if ($this->RegisterVariableString('Status', $this->Translate('Status'), [], 0)) {
            $this->SetValue('Status', '{}');
        }

        $this->RegisterTimer('Scheduler', 0, 'IPS_RequestAction($_IPS["TARGET"], "Scheduler", "");');
        $this->RegisterTimer('Publisher', 0, 'IPS_RequestAction($_IPS["TARGET"], "Publisher", "");');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('Scheduler', 0);
        $this->SetTimerInterval('Publisher', 0);

        if (!$this->lock()) {
            return;
        }
        try {
            $this->clearMessagesAndReferences();
            $this->SetBuffer('Queue', '[]');
            $this->SetBuffer('VariableMap', '{}');
            $this->SetBuffer('LastProbeAt', '0');
        } finally {
            $this->unlock();
        }

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        if (!$this->validateConfiguration()) {
            $this->publishStatus();
            return;
        }

        $this->maintainOptionalVariables();

        if (!$this->lock()) {
            return;
        }
        try {
            $this->buildRuntimeState();
            $this->scheduleNext();
        } finally {
            $this->unlock();
        }

        $this->SetStatus(IS_ACTIVE);
        $this->publishStatus();
        $this->SetTimerInterval('Publisher', $this->ReadPropertyInteger('PublishInterval') * 1000);
    }

    public function GetConfigurationForm(): string
    {
        $counters = [];
        foreach (self::STATUSES as $status) {
            $counters[] = [
                'type'    => 'CheckBox',
                'name'    => 'Counter' . $status,
                'caption' => 'Count ' . $status
            ];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'Interval',
                    'caption' => 'Check interval',
                    'suffix'  => 'seconds',
                    'minimum' => 1,
                    'maximum' => self::MAX_INTERVAL
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'ProbeTimeout',
                    'caption' => 'Probe timeout',
                    'suffix'  => 'seconds',
                    'minimum' => 1
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'CommandInterval',
                    'caption' => 'Minimum time between probes',
                    'suffix'  => 'milliseconds',
                    'minimum' => 1,
                    'maximum' => self::MAX_COMMAND_INTERVAL
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
                        ],
                        [
                            'caption' => 'Variable',
                            'name'    => 'Variable',
                            'width'   => '100px',
                            'add'     => false,
                            'edit'    => ['type' => 'CheckBox']
                        ]
                    ],
                    'values' => []
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Counter variables',
                    'items'   => $counters
                ]
            ],
            'status' => [
                ['code' => IS_ACTIVE, 'icon' => 'active', 'caption' => 'Watchdog active.'],
                ['code' => IS_EBASE + 1, 'icon' => 'error', 'caption' => 'Check interval must be between 1 and 86400 seconds.'],
                ['code' => IS_EBASE + 2, 'icon' => 'error', 'caption' => 'Minimum time between probes must be between 1 and 60000 milliseconds.'],
                ['code' => IS_EBASE + 3, 'icon' => 'error', 'caption' => 'Maximum tries must be greater than zero.'],
                ['code' => IS_EBASE + 4, 'icon' => 'error', 'caption' => 'Publish interval must be greater than zero.'],
                ['code' => IS_EBASE + 5, 'icon' => 'error', 'caption' => 'An LCN module must be selected in every row.'],
                ['code' => IS_EBASE + 6, 'icon' => 'error', 'caption' => 'An LCN module may only be configured once.'],
                ['code' => IS_EBASE + 7, 'icon' => 'error', 'caption' => 'Probe timeout must be at least 1 second and shorter than the check interval.']
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
            return;
        }

        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Scheduler':
                $this->runScheduler();
                return;
            case 'Publisher':
                $this->publishStatus();
                return;
        }

        throw new Exception('Invalid ident: ' . $Ident);
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

    private function runScheduler(): void
    {
        // The probe is sent after the lock is released, so life signs are never blocked by the LCN call
        $probe = null;
        $statusChanged = false;

        if (!$this->lock()) {
            return;
        }
        try {
            $queue = $this->getQueue();

            while ($queue !== []) {
                $now = time();
                $head = $queue[0];

                if ((int) $head['NextDue'] > $now) {
                    break;
                }

                if (!(bool) $head['Enabled']) {
                    array_shift($queue);
                    $head['Status'] = self::STATUS_DISABLED;
                    $head['Try'] = 0;
                    $head['NextDue'] = $now + $this->ReadPropertyInteger('Interval');
                    $this->enqueue($queue, $head);
                    continue;
                }

                $moduleID = (int) $head['InstanceID'];
                if (!IPS_InstanceExists($moduleID)) {
                    array_shift($queue);
                    $statusChanged = true;
                    continue;
                }

                // Watch variables may have been created since the last check
                if ((int) $head['ProbeType'] < 0) {
                    $oldStatus = (string) $head['Status'];
                    $variableMap = $this->decodeObject($this->GetBuffer('VariableMap'));
                    $head = $this->applyProbe($head, $this->attachWatchVariables($moduleID, $variableMap));
                    $this->SetBuffer('VariableMap', json_encode((object) $variableMap, JSON_THROW_ON_ERROR));
                    $statusChanged = $statusChanged || $oldStatus !== (string) $head['Status'];

                    if ((int) $head['ProbeType'] < 0) {
                        array_shift($queue);
                        $head['NextDue'] = $now + $this->ReadPropertyInteger('Interval');
                        $this->enqueue($queue, $head);
                        continue;
                    }
                    $queue[0] = $head;
                }

                $isOffline = (string) $head['Status'] === self::STATUS_OFFLINE;
                if (!$isOffline && (int) $head['Try'] >= $this->ReadPropertyInteger('MaxTries')) {
                    array_shift($queue);
                    $head['Status'] = self::STATUS_OFFLINE;
                    $head['NextDue'] = $now + $this->ReadPropertyInteger('Interval');
                    $this->enqueue($queue, $head);
                    $statusChanged = true;
                    continue;
                }

                $preciseNow = microtime(true);
                if ($preciseNow < $this->commandReadyAt()) {
                    break;
                }

                array_shift($queue);
                $head['Try'] = (int) $head['Try'] + 1;
                $head['LastTrigger'] = $now;
                $head['NextDue'] = $now + ($isOffline
                    ? $this->ReadPropertyInteger('Interval')
                    : $this->ReadPropertyInteger('ProbeTimeout'));
                $this->SetBuffer('LastProbeAt', (string) $preciseNow);
                $this->enqueue($queue, $head);
                $probe = $head;
                break;
            }

            $this->setQueue($queue);
            $this->scheduleNext();
        } finally {
            $this->unlock();
        }

        if ($probe !== null) {
            $this->sendProbe($probe);
        }
        if ($statusChanged) {
            $this->publishStatus();
        }
    }

    private function handleLifeSign(int $moduleID): void
    {
        $statusChanged = false;

        if (!$this->lock()) {
            return;
        }
        try {
            $queue = $this->getQueue();
            $now = time();

            foreach ($queue as $index => $entry) {
                if ((int) $entry['InstanceID'] !== $moduleID) {
                    continue;
                }

                array_splice($queue, $index, 1);
                $oldStatus = (string) $entry['Status'];
                $entry['LastSeen'] = $now;
                $entry['Try'] = 0;
                $entry['NextDue'] = $now + $this->ReadPropertyInteger('Interval');
                $entry['Status'] = (bool) $entry['Enabled'] ? self::STATUS_ALIVE : self::STATUS_DISABLED;
                $this->enqueue($queue, $entry);
                $statusChanged = $oldStatus !== (string) $entry['Status'];

                $this->setQueue($queue);
                $this->scheduleNext();
                break;
            }
        } finally {
            $this->unlock();
        }

        if ($statusChanged) {
            $this->publishStatus();
        }
    }

    private function scheduleNext(): void
    {
        $queue = $this->getQueue();
        if ($queue === []) {
            $this->SetTimerInterval('Scheduler', 0);
            return;
        }

        $head = $queue[0];
        $wakeAt = (float) $head['NextDue'];

        if ((bool) $head['Enabled']) {
            $wakeAt = max($wakeAt, $this->commandReadyAt());
        }

        $delay = ($wakeAt - microtime(true)) * 1000;
        $delayMilliseconds = (int) min(max(1, ceil($delay)), self::MAX_TIMER_MILLISECONDS);
        $this->SetTimerInterval('Scheduler', $delayMilliseconds);
    }

    // Keeps the queue sorted by NextDue; new entries usually belong at the end
    private function enqueue(array &$queue, array $entry): void
    {
        $position = count($queue);
        while ($position > 0 && (int) $queue[$position - 1]['NextDue'] > (int) $entry['NextDue']) {
            $position--;
        }
        array_splice($queue, $position, 0, [$entry]);
    }

    private function publishStatus(): void
    {
        $status = [];
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->getQueue() as $entry) {
            $moduleStatus = (string) $entry['Status'];
            $status[(int) $entry['InstanceID']] = $moduleStatus;
            $counts[$moduleStatus] = ($counts[$moduleStatus] ?? 0) + 1;

            if ($moduleStatus === self::STATUS_ALIVE || $moduleStatus === self::STATUS_OFFLINE) {
                $this->setOptionalValue('Module' . (int) $entry['InstanceID'], $moduleStatus === self::STATUS_ALIVE);
            }
        }
        ksort($status, SORT_NUMERIC);
        $this->SetValue('Status', json_encode((object) $status, JSON_THROW_ON_ERROR));

        foreach (self::STATUSES as $moduleStatus) {
            $this->setOptionalValue('Count' . $moduleStatus, $counts[$moduleStatus]);
        }
    }

    private function buildRuntimeState(): void
    {
        $queue = [];
        $variableMap = [];
        // All modules are probed shortly after startup; CommandInterval keeps them apart
        $nextDue = time() + min(self::STARTUP_DELAY, $this->ReadPropertyInteger('Interval'));

        foreach ($this->decodeArray($this->ReadPropertyString('Modules')) as $row) {
            $moduleID = (int) ($row['InstanceID'] ?? 0);
            if ($moduleID <= 0 || !IPS_InstanceExists($moduleID)) {
                continue;
            }

            $enabled = (bool) ($row['Enabled'] ?? true);
            $this->RegisterReference($moduleID);

            $entry = [
                'InstanceID'  => $moduleID,
                'Enabled'     => $enabled,
                'Status'      => $enabled ? self::STATUS_UNKNOWN : self::STATUS_DISABLED,
                'LastSeen'    => 0,
                'LastTrigger' => 0,
                'Try'         => 0,
                'NextDue'     => $nextDue,
                'ProbeType'   => -1,
                'ProbeTarget' => 0
            ];
            $queue[] = $this->applyProbe($entry, $this->attachWatchVariables($moduleID, $variableMap));
        }

        $this->setQueue($queue);
        $this->SetBuffer('VariableMap', json_encode((object) $variableMap, JSON_THROW_ON_ERROR));
    }

    private function rebuildWatchSources(): void
    {
        $statusChanged = false;

        if (!$this->lock()) {
            return;
        }
        try {
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
                $oldStatus = (string) $entry['Status'];
                $entry = $this->applyProbe($entry, $this->attachWatchVariables($moduleID, $variableMap));
                $statusChanged = $statusChanged || $oldStatus !== (string) $entry['Status'];
            }
            unset($entry);

            $this->setQueue($queue);
            $this->SetBuffer('VariableMap', json_encode((object) $variableMap, JSON_THROW_ON_ERROR));
        } finally {
            $this->unlock();
        }

        if ($statusChanged) {
            $this->publishStatus();
        }
    }

    // Registers the watch variables of an LCN module and returns the probe to use
    private function attachWatchVariables(int $moduleID, array &$variableMap): array
    {
        $watchVariables = $this->getWatchVariables($moduleID);
        foreach ($watchVariables as $variable) {
            $variableID = (int) $variable['ID'];
            $this->RegisterMessage($variableID, VM_UPDATE);
            $this->RegisterMessage($variableID, VM_DELETE);
            $variableMap[(string) $variableID] = $moduleID;
        }

        return $this->getProbe($moduleID, $watchVariables);
    }

    private function applyProbe(array $entry, array $probe): array
    {
        $entry['ProbeType'] = $probe['Type'];
        $entry['ProbeTarget'] = $probe['Target'];

        if (!(bool) $entry['Enabled']) {
            return $entry;
        }

        $status = (string) $entry['Status'];
        if ($probe['Type'] < 0 && $status !== self::STATUS_NOPROBE) {
            $entry['Status'] = self::STATUS_NOPROBE;
            $entry['Try'] = 0;
            $this->LogMessage(
                sprintf('No probe available for LCN module %s (%d)', IPS_GetName((int) $entry['InstanceID']), (int) $entry['InstanceID']),
                KL_WARNING
            );
        } elseif ($probe['Type'] >= 0 && $status === self::STATUS_NOPROBE) {
            $entry['Status'] = self::STATUS_UNKNOWN;
            $entry['Try'] = 0;
        }

        return $entry;
    }

    private function validateConfiguration(): bool
    {
        $interval = $this->ReadPropertyInteger('Interval');
        if ($interval < 1 || $interval > self::MAX_INTERVAL) {
            $this->SetStatus(IS_EBASE + 1);
            return false;
        }
        if ($this->ReadPropertyInteger('CommandInterval') < 1 || $this->ReadPropertyInteger('CommandInterval') > self::MAX_COMMAND_INTERVAL) {
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
        if ($this->ReadPropertyInteger('ProbeTimeout') < 1 || $this->ReadPropertyInteger('ProbeTimeout') >= $interval) {
            $this->SetStatus(IS_EBASE + 7);
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

    // -------------------------------------------------------------------------
    // Optional variables
    // -------------------------------------------------------------------------

    private function maintainOptionalVariables(): void
    {
        $wanted = ['Status' => true];

        $position = 1;
        foreach (self::STATUSES as $status) {
            $ident = 'Count' . $status;
            if ($this->ReadPropertyBoolean('Counter' . $status)) {
                $this->RegisterVariableInteger($ident, $this->Translate('Count ' . $status), [], $position);
                $wanted[$ident] = true;
            }
            $position++;
        }

        $position = self::MODULE_VARIABLE_POSITION;
        foreach ($this->decodeArray($this->ReadPropertyString('Modules')) as $row) {
            $moduleID = (int) ($row['InstanceID'] ?? 0);
            if (!(bool) ($row['Variable'] ?? false) || $moduleID <= 0 || !IPS_InstanceExists($moduleID)) {
                continue;
            }
            $ident = 'Module' . $moduleID;
            $this->RegisterVariableBoolean($ident, IPS_GetName($moduleID), [], $position++);
            $wanted[$ident] = true;
        }

        // Variables that are no longer wanted are kept, only released from the module
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = (string) IPS_GetObject($childID)['ObjectIdent'];
            if (preg_match('/^(Count[A-Za-z]+|Module\d+)$/', $ident) === 1 && !isset($wanted[$ident])) {
                IPS_SetIdent($childID, '');
            }
        }

        // Variables in use are referenced; released ones lost their reference in clearMessagesAndReferences()
        foreach (array_keys($wanted) as $ident) {
            $this->RegisterReference($this->GetIDForIdent((string) $ident));
        }
    }

    private function setOptionalValue(string $ident, mixed $value): void
    {
        if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
            return;
        }
        $this->SetValue($ident, $value);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function lock(): bool
    {
        if (IPS_SemaphoreEnter('MWLCN_' . $this->InstanceID, self::LOCK_TIMEOUT)) {
            return true;
        }
        $this->SendDebug('Lock', 'Timeout while waiting for lock', 0);
        return false;
    }

    private function unlock(): void
    {
        IPS_SemaphoreLeave('MWLCN_' . $this->InstanceID);
    }

    private function getQueue(): array
    {
        return $this->decodeArray($this->GetBuffer('Queue'));
    }

    private function setQueue(array $queue): void
    {
        $this->SetBuffer('Queue', json_encode(array_values($queue), JSON_THROW_ON_ERROR));
    }

    private function commandReadyAt(): float
    {
        return (float) $this->GetBuffer('LastProbeAt') + $this->ReadPropertyInteger('CommandInterval') / 1000;
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
        foreach (self::PROBE_PRIORITY as $type) {
            foreach ($watchVariables as $variable) {
                if ((int) $variable['Cmd'] !== $type) {
                    continue;
                }
                // Status requests go to the LCN module, all other requests to the device instance
                $target = $type === 1 ? $moduleID : IPS_GetParent((int) $variable['ID']);
                return ['Type' => $type, 'Target' => $target];
            }
        }

        return ['Type' => -1, 'Target' => 0];
    }

    private function sendProbe(array $entry): bool
    {
        $type = (int) $entry['ProbeType'];
        $target = (int) $entry['ProbeTarget'];
        if ($target <= 0) {
            return false;
        }

        $this->SendDebug('Probe', sprintf('Type %d to %d (LCN module %d, try %d)', $type, $target, (int) $entry['InstanceID'], (int) $entry['Try']), 0);

        try {
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
        } catch (\Throwable $e) {
            $this->SendDebug('Probe', sprintf('Probe to %d failed: %s', $target, $e->getMessage()), 0);
        }

        return false;
    }
}
