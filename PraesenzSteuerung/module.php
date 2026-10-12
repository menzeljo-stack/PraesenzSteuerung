<?php
class PraesenzSteuerung extends IPSModule {
    
    public function Create() {
        parent::Create();
        
        $this->RegisterPropertyString("PresenceSensors", "[]");
        $this->RegisterPropertyString("Targets", "[]");
        $this->RegisterPropertyInteger("DelayTime", 60);
        
        $this->RegisterTimer("OffTimer", 0, 'IPS_RequestAction($_IPS["TARGET"], "OffTimer", "");');
        
        $this->SetBuffer("ExpectedState", "0");
        $this->SetBuffer("ManualOverride", "NONE");
        $this->SetBuffer("LastPresenceState", "0");
        $this->SetBuffer("LastActionTime", "0");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        
        $sensors = json_decode($this->ReadPropertyString("PresenceSensors"), true);
        if (is_array($sensors)) {
            foreach ($sensors as $sensor) {
                if (IPS_VariableExists($sensor['VariableID'])) {
                    $this->RegisterMessage($sensor['VariableID'], VM_UPDATE);
                }
            }
        }
        
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
            // $Data[0] ist der neue Wert, $Data[1] gibt an, ob sich der Wert geändert hat
            $this->EvaluateTarget($SenderID, $Data[0], $Data[1]);
        }
    }

    private function EvaluatePresence() {
        $sensors = json_decode($this->ReadPropertyString("PresenceSensors"), true);
        $overallPresence = false;
        
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
            // Präsenz erkannt (steigende Flanke)
            if ($manualOverride != "ON") {
                $this->SetBuffer("ManualOverride", "NONE"); 
                $this->SetTimerInterval("OffTimer", 0);
                $this->SetBuffer("ExpectedState", "1");
                $this->SetBuffer("LastActionTime", (string)time());
                $this->SwitchTargets(true);
            }
            
        } elseif ($overallPresence && $lastPresence) {
            // Präsenz bleibt bestehen
            if ($manualOverride != "ON") {
                $this->SetTimerInterval("OffTimer", 0);
            }
            
        } elseif (!$overallPresence && $lastPresence) {
            // Präsenz beendet (fallende Flanke)
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

    private function EvaluateTarget($targetID, $newValue, $hasChanged) {
        $expectedState = $this->GetBuffer("ExpectedState") === "1";
        $boolNewValue = (bool)$newValue;
        $lastAction = (int)$this->GetBuffer("LastActionTime");
        
        // Fall 1: Physikalischer Wechsel entgegen der Automatik
        if ($boolNewValue != $expectedState) {
            if ($boolNewValue) {
                $this->SetBuffer("ManualOverride", "ON");
            } else {
                $this->SetBuffer("ManualOverride", "OFF");
            }
            $this->SetTimerInterval("OffTimer", 0);
            
            // WICHTIG: Erwartung anpassen, damit Folgeschaltungen korrekt erkannt werden
            $this->SetBuffer("ExpectedState", $boolNewValue ? "1" : "0");
        } 
        // Fall 2: Der Schalter wurde erneut auf "AN" gedrückt, obwohl er schon "AN" war
        else if (!$hasChanged && $boolNewValue === true && $expectedState === true) {
            // Zeit prüfen, um Echos der Skript-eigenen Schaltung zu ignorieren (3 Sekunden Puffer)
            if (time() - $lastAction > 3) {
                // Hinweis für Shelly-Nutzer: Falls ein Gerät zyklisch seinen Status sendet,
                // kann dies als Tastendruck gewertet werden. In dem Fall das Licht kurz Aus und wieder An schalten.
                $this->SetBuffer("ManualOverride", "ON");
                $this->SetTimerInterval("OffTimer", 0);
            }
        }
        
        // Override aufheben, wenn manuell ausgeschaltet wurde und der Raum leer ist
        if (!$boolNewValue && $this->GetBuffer("ManualOverride") == "OFF" && $this->GetBuffer("LastPresenceState") == "0") {
             $this->SetBuffer("ManualOverride", "NONE");
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
        $this->SetBuffer("ManualOverride", "NONE");
        $this->SetBuffer("LastActionTime", (string)time());
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