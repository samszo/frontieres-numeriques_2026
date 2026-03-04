<?php
require_once 'ResumeGenerator.php';
require_once 'ContextDistiller.php';
require_once 'ProgrammeGenerator.php';
require_once 'key.php';

$csvFile = "liste_auteurs.csv";
$outputFolder = "auteurs_quarto";
$omkApiUrl = "http://localhost/omk_paragraphe";
$omkApiUrl = "https://humanum-p8.fr/paragraphe";
$context = false;

//$omkApiIdentity="2le19tgSwb9lhlNEEM72HWEjS993snkM";
//$omkApiCredential="9pBg2L7865XwFq50TOEtSL2MGR4jCsJ7";

if (!file_exists($outputFolder)) mkdir($outputFolder, 0777, true);

//récupère les infos de l'événement
$omkConfItem = fetchOmekaJson($omkApiUrl, "items/54151"); 
//Récupère le slug du premier site
$omkSite = fetchOmekaJson("", "",$omkConfItem["o:site"][0]["@id"]); 
//récupère le titre du projet
$currentProjet = $omkConfItem["foaf:currentProject"][0]["@value"];

//$urlSiteConf = "https://samszo.github.io/frontieres-numeriques_2026/index.html";
$fileName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', 'rawtext_'.$omkConfItem['o:title']))) . ".txt";
if (!file_exists($outputFolder . DIRECTORY_SEPARATOR . $fileName)){
    $urlSiteConf = $omkConfItem["foaf:homepage"][0]["@id"];
    $distiller = new ContextDistiller($apiKey);
    $rawText = $distiller->fetchRawContent($urlSiteConf);
    file_put_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName, $rawText);
}else{
    $rawText = file_get_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName);
}

if ($rawText) {
    $fileNameContext = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', 'context_'.$omkConfItem['o:title']))) . ".json";
    if (!file_exists($outputFolder . DIRECTORY_SEPARATOR . $fileNameContext)){
        $refinedContext = $distiller->distillContext($rawText);
        file_put_contents($outputFolder . DIRECTORY_SEPARATOR . $fileNameContext, json_encode($refinedContext));
    }else{
        $refinedContext = file_get_contents($outputFolder . DIRECTORY_SEPARATOR . $fileNameContext);
        $refinedContext = json_decode($refinedContext,true);
    }
    if(isset($refinedContext['candidates'][0]['content']['parts'][0]['text'])){
        $context = $refinedContext['candidates'][0]['content']['parts'][0]['text'];
        preg_match('/```json\s*(.*?)\s*```/s', $context, $matches);
        if (!empty($matches[1])) {
            $context = json_decode($matches[1], true);
        }
    }else{
        $context = "";
        echo "Erreur lors de la création du contexte.";
    }
} else {
    echo "Erreur lors de la récupération du site.";
}
//
if($context){
    $generator = new ResumeGenerator($apiKey,$context,$omkConfItem,$outputFolder,$omkSite);

    //récupère les auteurs participant à l'événement
    /*demande trop de mémoire
    $query = "items?property[0][joiner]=and&property[0][property]=253&property[0][type]=res&property[0][text]=54151";
    $auteurs = fetchOmekaJson($omkApiUrl,$query);
    */
    //via un bulkexport
    $csvFile = $omkConfItem["bibo:authorList"][0]["@id"];// "http://localhost/omk_paragraphe/files/bulk_export/csv-20260301-083851.csv";
    $ch = curl_init($csvFile);
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);    
    $auteurs = [];
    $lines = str_getcsv($response, "\n");
    $auteurs = [];
    foreach ($lines as $k=>$line) {
        $data = str_getcsv($line, ",");
        if($k==0)$headers = $data;
        elseif (!empty($data[0])) {
            $auteurs[] = $data[0];
        }
    }
    
    /*
    $query = "items/72730";
    $auteur = fetchOmekaJson($omkApiUrl,$query);
    $auteurs = [$auteur];
    */

    //$auteurs=["63049"];
    
    $props = [];

    foreach ($auteurs as $id) {        

        $query = "items/".$id;
        $auteur = fetchOmekaJson($omkApiUrl,$query);
        $fileName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $auteur['o:title'])));

        //ne regènere pas les auteurs avec un projet en cours correspondant au site de la conférence car modifié à la main
        //TODO :récupérer automatiquement les proposition de l'auteur
        $genere = true;
        foreach ($auteur["labo:EventActor"] as $event) {
            if(isset($event["@annotation"]) && isset($event["@annotation"]["foaf:currentProject"])){
                foreach ($event["@annotation"]["foaf:currentProject"] as $cp) {
                    if($cp["o:label"]==$currentProjet){
                        $genere = false;
                        $md = file_get_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName.".qmd");
                    }
                }
            } 
        }
        if(!$genere){
           echo "Pas de Traitement : " . $auteur['o:title'] . "<br>";
        }else{

            echo "Traitement : " . $auteur['o:title'] . "<br>";
            $md = $generator->generate($auteur);
            
            if($md){
                //récupère le titre
                preg_match('/\*\*Titre\s*:\s*(.+?)\*\*/s', $md, $mTitre);
                if (!empty($mTitre[1])) {
                    $titre = trim($mTitre[1]);
                }else{
                    $titre = " --- ";
                }
                //ajoute un sous titre
                $subtitre = str_replace('"',"-",$titre);
                $md = str_replace("bibliography: referenceAuteurs.bib","subtitle: \"$subtitre\"\nbibliography: referenceAuteurs.bib\n\n",$md);

                file_put_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName. ".qmd", $md);
            }
        }
        $props[]=["id"=>$auteur['o:id'],"auteur"=>$auteur['o:title'],"titre"=>$titre,'page'=>$outputFolder . DIRECTORY_SEPARATOR . $fileName.".html"];
    }

    $program = new ProgrammeGenerator($props,$omkConfItem,$fileNameContext);
    $program->generate();

    // Export $props to CSV
    $csvOutput = fopen($outputFolder . DIRECTORY_SEPARATOR . "programme.csv", "w");
    fputcsv($csvOutput, ["id", "auteur", "titre", "page"]);
    foreach ($props as $prop) {
        fputcsv($csvOutput, [$prop["id"], $prop["auteur"], $prop["titre"], $prop["page"]]);
    }
    fclose($csvOutput);
    echo "Programme exporté en CSV<br>";

}

function fetchOmekaJson($baseUrl, $endpoint, $url="") {
    if(!$url){
        $url = rtrim($baseUrl, '/') . '/api/' . ltrim($endpoint, '/');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    try {
        $response = curl_exec($ch);
    } catch (\Throwable $th) {
        throw $th;
    }
    if ($response === false) {
        return null;
    }
    return json_decode($response, true);
}

?>