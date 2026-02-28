<?php

class ProgrammeGenerator {
    private $propositions;
    private $outputFile = "programme.qmd";
    private $auteursFolder = "auteurs_quarto";
    private $siteItem;
    private $slotDuration = 30; // minutes
    private $pauseDuration = 30; // minutes
    private $interventionsBeforePause = 4;
    private $startTime = "09:00";
    private $lunchTime = "12:30";
    private $lunchDuration = 90; // minutes

    public function __construct($propositions,$siteItem) {
        $this->propositions = $propositions;
        $this->siteItem = $siteItem;
    }

    private function getSlug($name) {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $name)));
    }

    public function generate() {

        // Répartition simple : moitié Jour 1, moitié Jour 2
        $total = count($this->propositions);
        $split = ceil($total / 2.5);
        
        $jours = [
            "Jour 1 : Conceptions" => array_slice($this->propositions, 0, $split),
            "Jour 2 : Créations" => array_slice($this->propositions, $split),
            "Jour 3 : Expériementations" => array_slice($this->propositions, $split)
        ];        


        $md = "---" . PHP_EOL;
        $md .= "title: \"**PROGRAMME PROVISOIRE** - {$this->siteItem['o:title']} \"" . PHP_EOL;
        $md .= "subtitle: \" {$this->siteItem['curation:location'][0]['@value']} | {$this->getPeriodeStr($this->siteItem['dcterms:date'][0]['@value'])} \"" . PHP_EOL;
        $md .= "---" . PHP_EOL . PHP_EOL;

        $md .= "Ce programme regroupe les propositions sélectionnées pour le colloque. Cliquez sur le nom d'un intervenant pour consulter le résumé détaillé et les résonances bibliographiques." . PHP_EOL . PHP_EOL;


        foreach ($jours as $titre => $liste) {
            $md .= "## $titre" . PHP_EOL . PHP_EOL;
            $md .= "| Horaire | Type | Intervenant | Détails |" . PHP_EOL;
            $md .= "| :--- | :--- | :--- | :--- |" . PHP_EOL;

            $currentTime = strtotime($this->startTime);
            $counter = 0;
            $dej = false;

            foreach ($this->propositions as $prop) {
                // Gestion du déjeuner
                if (date("H:i", $currentTime) >= $this->lunchTime && !$dej) {
                    $md .= "| " . date("H:i", $currentTime) . " | **DÉJEUNER** | *Pause Gastronomique* | |" . PHP_EOL;
                    $currentTime += $this->lunchDuration * 60;
                    $dej=true;
                }

                // Insertion de la pause toutes les 4 interventions
                if ($counter > 0 && $counter % $this->interventionsBeforePause == 0) {
                    $md .= "| " . date("H:i", $currentTime) . " | **PAUSE** | *Networking & Café* | |" . PHP_EOL;
                    $currentTime += $this->pauseDuration * 60;
                }

                // Ligne d'intervention
                $slug = $this->getSlug($prop['auteur']);
                $md .= "| " . date("H:i", $currentTime) . " | Communication | {$prop['auteur']} - *{$prop['titre']}* | [Consulter la proposition]({$this->auteursFolder}/{$slug}.qmd) |" . PHP_EOL;
                
                // Incrémentation
                $currentTime += $this->slotDuration * 60;
                $counter++;
            }
            $md .= PHP_EOL . "---" . PHP_EOL . PHP_EOL;
        }
        
        $md .= "| " . date("H:i", $currentTime) . " | Clôture | Synthèse des travaux |" . PHP_EOL;

        file_put_contents($this->outputFile, $md);
        echo "✅ Le fichier '$this->outputFile' a été généré avec succès." . PHP_EOL;
    }

    public function getPeriodeStr($periode){

        $dates = explode("/",$periode);

        return $this->getDate($dates[0]). " - ".$this->getDate($dates[1]);
    }

    function getDate($d){

        $date = new DateTime($d);
        $dayOfWeek = $date->format('l'); // Jour de la semaine en anglais
        $dayNumber = $date->format('d'); // Numéro du jour
        $monthName = $date->format('F'); // Nom du mois en anglais
        $year = $date->format('Y'); // Année

        // Convertir en français si nécessaire
        $daysFR = ['Monday' => 'lundi', 'Tuesday' => 'mardi', 'Wednesday' => 'mercredi', 'Thursday' => 'jeudi', 'Friday' => 'vendredi', 'Saturday' => 'samedi', 'Sunday' => 'dimanche'];
        $monthsFR = ['January' => 'janvier', 'February' => 'février', 'March' => 'mars', 'April' => 'avril', 'May' => 'mai', 'June' => 'juin', 'July' => 'juillet', 'August' => 'août', 'September' => 'septembre', 'October' => 'octobre', 'November' => 'novembre', 'December' => 'décembre'];

        $dayOfWeekFR = $daysFR[$dayOfWeek];
        $monthNameFR = $monthsFR[$monthName];
        $dateFormatted = "$dayOfWeekFR $dayNumber $monthNameFR $year";

        return $dateFormatted;

    }
}