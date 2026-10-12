<?php
class PraesenzSteuerung extends IPSModule {
    
    public function Create() {
        parent::Create();
        
        $this->RegisterPropertyString("PresenceSensors", "[]");
        $this->RegisterPropertyString("Targets", "[]");
        $this->RegisterPropertyInteger("DelayTime", 60);
        
        // Timer für den Nachlauf
        $this->RegisterTimer("OffTimer", 0, 'IPS_RequestAction($_IPS["TARGET"], "OffTimer", "");');
        
        // Interne Zustände
        $this->SetBuffer("ExpectedState", "0");
        $this->SetBuffer("ManualOverride", "NONE");
        $this->SetBuffer("LastPresenceState", "0");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        
        // Alle alten Events löschen, um doppelte Trigger zu vermeiden
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        
        // Präsenzmelder abonnieren
        $sensors = json_decode($this->ReadPropertyString("PresenceSensors"), true);
        if (is_array($sensors)) {
            foreach ($sensors as $sensor) {
                if (IPS_VariableExists($sensor['VariableID'])) {
                    $this->RegisterMessage($sensor['VariableID'], VM_UPDATE);
                }
            }
        }
        
        // Ziel-Geräte abonnieren (für die Erkennung der manuellen Schaltung)
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        if (is_array($targets)) {
            foreach ($targets as $target) {
                if (IPS_VariableExists($target['VariableID'])) {
                    $this->RegisterMessage($target['VariableID'], VM_UPDATE);
                }
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        $sensors = json_decode($this->ReadPropertyString("PresenceSensors"), true);
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        
        $isSensor = false;
        if (is_array($sensors)) {
            foreach ($sensors as $sensor) {
                if ($sensor['VariableID'] == $SenderID) {
                    $isSensor = true;
                    break;
                }
            }
        }
        
        if ($isSensor) {
            $this->EvaluatePresence();
            return;
        }
        
        $isTarget = false;
        if (is_array($targets)) {
            foreach ($targets as $target) {
                if ($target['VariableID'] == $SenderID) {
                    $isTarget = true;
                    break;
                }
            }
        }
        
        if ($isTarget) {
            // $Data[0] enthält bei VM_UPDATE den neuen Wert
            $this->EvaluateTarget($SenderID, $Data[0]);
        }
    }

    private function EvaluatePresence() {
        $sensors = json_decode($this->ReadPropertyString("PresenceSensors"), true);
        $overallPresence = false;
        
        // ODER-Verknüpfung aller Sensoren
        if (is_array($sensors)) {
            foreach ($sensors as $sensor) {
                if (IPS_VariableExists($sensor['VariableID'])) {
                    if (GetValue($sensor['VariableID'])) {
                        $overallPresence = true;
                        break;
                    }
                }
            }
        }
        
        $lastPresence = $this->GetBuffer("LastPresenceState") === "1";
        $this->SetBuffer("LastPresenceState", $overallPresence ? "1" : "0");
        $manualOverride = $this->GetBuffer("ManualOverride");
        
        if ($overallPresence && !$lastPresence) {
            // Steigende Flanke (Präsenz erkannt)
            $this->SetBuffer("ManualOverride", "NONE"); 
            $this->SetTimerInterval("OffTimer", 0);
            $this->SetBuffer("ExpectedState", "1");
            $this->SwitchTargets(true);
            
        } elseif ($overallPresence && $lastPresence) {
            // Präsenz bleibt bestehen
            $this->SetTimerInterval("OffTimer", 0);
            
        } elseif (!$overallPresence && $lastPresence) {
            // Fallende Flanke (Präsenz beendet)
            if ($manualOverride != "ON") {
                $delay = $this->ReadPropertyInteger("DelayTime");
                if ($delay > 0) {
                    $this->SetTimerInterval("OffTimer", $delay * 1000);
                } else {
                    $this->TriggerOff();
                }
            }
        }
    }

    private function EvaluateTarget($targetID, $newValue) {
        $expectedState = $this->GetBuffer("ExpectedState") === "1";
        $boolNewValue = (bool)$newValue;
        
        if ($boolNewValue != $expectedState) {
            // Manuelle Änderung erkannt
            if ($boolNewValue) {
                $this->SetBuffer("ManualOverride", "ON");
            } else {
                $this->SetBuffer("ManualOverride", "OFF");
            }
            // Laufenden Timer abbrechen, da manueller Eingriff Vorrang hat
            $this->SetTimerInterval("OffTimer", 0);
        }
    }

    public function RequestAction($Ident, $Value) {
        if ($Ident == "OffTimer") {
            $this->TriggerOff();
        }
    }

    private function TriggerOff() {
        $this->SetTimerInterval("OffTimer", 0);
        $this->SetBuffer("ExpectedState", "0");
        $this->SwitchTargets(false);
    }

    private function SwitchTargets($state) {
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        if (is_array($targets)) {
            foreach ($targets as $target) {
                if (IPS_VariableExists($target['VariableID'])) {
                    @RequestAction($target['VariableID'], $state);
                }
            }
        }
    }
}
?>