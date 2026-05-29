<?php

class ProgrammeGenerator {
    private $propositions;
    private $outputFile = "programme.qmd";
    private $auteursFolder = "auteurs_quarto";
    private $siteItem;
    private $slotDuration = 30; // minutes
    private $pauseDuration = 30; // minutes
    private $interventionsBeforePause = 3;
    private $startTime = "10:00";
    private $lunchTime = "13:00";
    private $lunchDuration = 90; // minutes
    private $fileNameContext;

    public function __construct($propositions,$siteItem,$fileNameContext) {
        $this->propositions = $propositions;
        $this->siteItem = $siteItem;
        $this->fileNameContext=$fileNameContext;
    }

    private function getSlug($name) {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $name)));
    }

    public function generate() {

        // Répartition simple : moitié Jour 1, moitié Jour 2
        $total = count($this->propositions);
        $split = ceil($total / 2);
        
        $jours = [
            "Conceptions" => array_slice($this->propositions, 0, $split),
            "Créations" => array_slice($this->propositions, $split, $split),
            //"Expériementations" => array_slice($this->propositions, $split*2)
        ];        

        $dateStr = $this->getPeriodeStr($this->siteItem['dcterms:date'][0]['@value']);
        $md = "---" . PHP_EOL;
        $md .= "title: \"**PROGRAMME** - {$this->siteItem['o:title']} \"" . PHP_EOL;
        $md .= "subtitle: \" {$this->siteItem['curation:location'][0]['@value']} | du {$dateStr[0]} au {$dateStr[count($dateStr)-1]} \"" . PHP_EOL;
        $md .= "toc: true" . PHP_EOL;
        $md .= "---" . PHP_EOL . PHP_EOL;

        $numJour = 0;
        foreach ($jours as $titre => $liste) {

            $currentTime = strtotime($this->startTime);
            $counter = 0;

            $md .= "# ".$dateStr[$numJour]." " . PHP_EOL . PHP_EOL;

            if($numJour == 0){
                $md .= "## Accueil - Ouverture du colloque : ".$this->formatDateHeure($currentTime) . PHP_EOL . PHP_EOL;
                $md .= "  - Comité de programme et d'organisation : " . PHP_EOL;
                $md .= "    - Malek Ghenima, Université de la Manouba, Tunisie" . PHP_EOL;
                $md .= "    - Olivier Nannipieri, Institut Méditerranéen des Sciences de l'Information et de la Communication, France" . PHP_EOL;
                $md .= "  - Présidents du colloque : ". PHP_EOL;
                $md .= "    -  Samuel Szoniecky, Université Paris 8, France" . PHP_EOL;
                $md .= "    -  Imad Saleh, Université Paris 8, France" . PHP_EOL. PHP_EOL;
                $currentTime += $this->slotDuration * 60;
                $counter++;
            }

            /*
            $md .= "## $titre" . PHP_EOL . PHP_EOL;
            $md .= "| Horaire | Type | Intervenant | Détails |" . PHP_EOL;
            $md .= "| :--- | :--- | :--- | :--- |" . PHP_EOL;
            */

            $md .= "```{=html}" . PHP_EOL;
            $md .= "<table>" . PHP_EOL;
            $md .= "<caption><h4>".$titre."</h4></caption>" . PHP_EOL;
            $md .= "<thead>" . PHP_EOL;
            $md .= "<tr>" . PHP_EOL;
            $md .= "<th>Horaire</th>" . PHP_EOL;
            $md .= "<th>Type</th>" . PHP_EOL;
            $md .= "<th>Intervenant</th>" . PHP_EOL;
            $md .= "<th>Détails</th>" . PHP_EOL;
            $md .= "</tr>" . PHP_EOL;
            $md .= "</thead>" . PHP_EOL;
            $md .= "<tbody>" . PHP_EOL;
            $numJour++;
            $dej = false;

            foreach ($liste as $i => $prop) {

                // Gestion du déjeuner
                if (date("H:i", $currentTime) >= $this->lunchTime && !$dej) {
                    //$md .= "| " . date("H:i", $currentTime) . " | **DÉJEUNER** | *Pause Gastronomique* | |" . PHP_EOL;
                    
                    $md .= "<tr class='dejeuner'>" . PHP_EOL;
                    $md .= "<td>".$this->formatDateHeure($currentTime)."</td>" . PHP_EOL;
                    $md .= "<td><b>DÉJEUNER</b></td>" . PHP_EOL;
                    $md .= "<td><i>Pause Gastronomique</i></td>" . PHP_EOL;
                    $md .= "<td></td>" . PHP_EOL;
                    $md .= "</tr>" . PHP_EOL;

                    $currentTime += $this->lunchDuration * 60;
                    $dej=true;
                }

                // Insertion de la pause toutes les 4 interventions
                if ($counter > 0 && $counter % $this->interventionsBeforePause == 0) {
                    //$md .= "| " . date("H:i", $currentTime) . " | **PAUSE** | *Networking & Café* | |" . PHP_EOL;

                    $md .= "<tr class='pause'>" . PHP_EOL;
                    $md .= "<td>".$this->formatDateHeure($currentTime)."</td>" . PHP_EOL;
                    $md .= "<td><b>PAUSE</b></td>" . PHP_EOL;
                    $md .= "<td><i>Networking & Café</i></td>" . PHP_EOL;
                    $md .= "<td></td>" . PHP_EOL;
                    $md .= "</tr>" . PHP_EOL;

                    $currentTime += $this->pauseDuration * 60;
                }

                // Ligne d'intervention
                $slug = $this->getSlug($prop['auteur']);
                //$md .= "| " . date("H:i", $currentTime) . " | Communication | {$prop['auteur']} - *{$prop['titre']}* | [Consulter la proposition]({$this->auteursFolder}/{$slug}.qmd) |" . PHP_EOL;
                $md .= "<tr id='dateSoutenance$currentTime'>" . PHP_EOL;
                $md .= "<td>".$this->formatDateHeure($currentTime).$prop['dateSoutenance']."</td>" . PHP_EOL;
                $md .= "<td>Communication</td>" . PHP_EOL;
                $md .= "<td>".ucwords(strtolower($prop['auteurs']))." - <i>{$prop['titre']}</i></td>" . PHP_EOL;
                $md .= "<td><a href='{$this->auteursFolder}/{$slug}.qmd'>Consulter la proposition</a></td>" . PHP_EOL;
                $md .= "</tr>" . PHP_EOL;
                
                // Incrémentation
                $currentTime += $this->slotDuration * 60;
                $counter++;
            }

            $md .= "</tbody>" . PHP_EOL;
            $md .= "</table>" . PHP_EOL;
            $md .= "```" . PHP_EOL;


            $md .= PHP_EOL . "---" . PHP_EOL . PHP_EOL;
        }
        
        $md .= "## Synthèse des travaux - Clôture : ".$this->formatDateHeure($currentTime) . PHP_EOL . PHP_EOL;
        $md .= "  - Comité de programme et d'organisation : " . PHP_EOL;
        $md .= "    - Malek Ghenima, Université de la Manouba, Tunisie" . PHP_EOL;
        $md .= "    - Olivier Nannipieri, Institut Méditerranéen des Sciences de l'Information et de la Communication, France" . PHP_EOL;
        $md .= "  - Présidents du colloque : ". PHP_EOL;
        $md .= "    -  Samuel Szoniecky, Université Paris 8, France" . PHP_EOL;
        $md .= "    -  Imad Saleh, Université Paris 8, France" . PHP_EOL. PHP_EOL;

        $md .= PHP_EOL . "---" . PHP_EOL . PHP_EOL;
        $md .= "# ".$dateStr[2]." " . PHP_EOL . PHP_EOL;

        $md .= "## Découvrir Djerba : départ 13 h 30" . PHP_EOL . PHP_EOL;
        $md .= "```{=html}" . PHP_EOL;
        $md .= "<embed src='assets/docs/excursion_FN26.pdf' width='800px' height='600px' />" . PHP_EOL;
        $md .= "```" . PHP_EOL;
        $md .= "### Merci de vous inscrire avec ce document : [fiche d'inscription](assets/docs/Excursion_Inscription_FN26.docx)" . PHP_EOL . PHP_EOL;

        /*
        $md .= "::: {.callout-warning}" . PHP_EOL;
        $md .= "Ce programme est toujours en cours de discussion avec les intervenants et le comité scientifique" . PHP_EOL;
        $md .= ":::" . PHP_EOL;
        */

        $md .= "::: {.callout-note appearance='minimal'}" . PHP_EOL;
        $md .= "## Processus de génération du programme" . PHP_EOL . PHP_EOL;
        $md .= "Ce programme regroupe les propositions déposées sur [sciencesconf.org](https://frontieresnum7.sciencesconf.org/submission/submit?lang=fr) et générées à partir des informations des auteurs présentes dans la base de données [Scanr](https://scanr.enseignementsup-recherche.gouv.fr/) et compilées dans le [site expériemental du Laboratoire Paragraphe](https://humanum-p8.fr/paragraphe/s/valorisations) pour plus de détails cf. [Module Omeka S Scanr](https://github.com/samszo/Omeka-S-module-Scanr)." . PHP_EOL . PHP_EOL;
        $md .= "Pour chaque auteur, un prompt est généré et soumis à Google Gemini (cf. [détails du raisonnement](https://gemini.google.com/share/af57cb425119)) pour créer au format [Markdown Quarto](https://quarto.org/) une proposition en lien avec le [contexte du colloque](../auteurs_quarto/$this->fileNameContext)." . PHP_EOL . PHP_EOL;
        $md .= "Le code et l'explication détaillée du processus sont accessibles sur github [{{< fa brands github >}} GitHub](https://github.com/samszo/frontieres-numeriques_2026/blob/main/PROCESSUS_GENERATION.md).".PHP_EOL . PHP_EOL;

        $md .= ":::". PHP_EOL . PHP_EOL;

        file_put_contents($this->outputFile, $md);
        echo "✅ Le fichier '$this->outputFile' a été généré avec succès." . PHP_EOL;
    }

    public function getPeriodeStr($periode){

        $dates = explode("/",$periode);

        $startDate = new DateTime($dates[0]);
        $endDate = new DateTime($dates[1]);
        $interval = $startDate->diff($endDate);
        $numDays = $interval->days;
        $dateStr = [];
        for ($i=0; $i <= $numDays; $i++) { 
            $dateStr[] = $this->getDate($startDate->format('Y-m-d'));
            $startDate->modify("+1 days");
        }

        return $dateStr;
    }

    function formatDateHeure($currentTime){
        return date("H", $currentTime)." h ".date("i", $currentTime);
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