<?php
require_once 'ResumeGenerator.php';
$apiKey = "VOTRE_CLE_API_GEMINI";
$csvFile = "liste_auteurs.csv";
$outputFolder = "auteurs_quarto";

if (!file_exists($outputFolder)) mkdir($outputFolder, 0777, true);
$generator = new ResumeGenerator($apiKey);

if (($handle = fopen($csvFile, "r")) !== FALSE) {
    $headers = fgetcsv($handle, 1000, ",");
    while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
        $row = array_combine($headers, $data);
        echo "Traitement : " . $row['auteur'] . "\n";
        $md = $generator->generate($row['auteur'], $row['publications'], $row['mots_cles'], $row['github'], $row['orcid']);
        $fileName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $row['auteur']))) . ".qmd";
        file_put_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName, $md);
    }
    fclose($handle);
}
?>