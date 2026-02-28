<?php
require_once 'ResumeGenerator.php';
require_once 'ContextDistiller.php';
require_once 'ProgrammeGenerator.php';
require_once 'key.php';

$csvFile = "liste_auteurs.csv";
$outputFolder = "auteurs_quarto";
$omkApiUrl = "https://humanum-p8.fr/omk_paragraphe";
$omkApiUrl = "http://localhost/omk_paragraphe";
$context = false;

//$omkApiIdentity="2le19tgSwb9lhlNEEM72HWEjS993snkM";
//$omkApiCredential="9pBg2L7865XwFq50TOEtSL2MGR4jCsJ7";

if (!file_exists($outputFolder)) mkdir($outputFolder, 0777, true);

//récupère les infos de l'événement
$omkItem = fetchOmekaJson($omkApiUrl, "items/54151"); 
//$urlSiteConf = "https://samszo.github.io/frontieres-numeriques_2026/index.html";
$fileName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', 'rawtext_'.$omkItem['o:title']))) . ".txt";
if (!file_exists($outputFolder . DIRECTORY_SEPARATOR . $fileName)){
    $urlSiteConf = $omkItem["foaf:homepage"][0]["@id"];
    $distiller = new ContextDistiller($apiKey);
    $rawText = $distiller->fetchRawContent($urlSiteConf);
    file_put_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName, $rawText);
}else{
    $rawText = file_get_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName);
}

if ($rawText) {
    $fileName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', 'context_'.$omkItem['o:title']))) . ".json";
    if (!file_exists($outputFolder . DIRECTORY_SEPARATOR . $fileName)){
        $refinedContext = $distiller->distillContext($rawText);
        file_put_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName, json_encode($refinedContext));
    }else{
        $refinedContext = file_get_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName);
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
    $generator = new ResumeGenerator($apiKey,$context,$omkItem,$outputFolder);

    //récupère les auteurs participant à l'événement
    /*demande trop de mémoire
    $query = "items?property[0][joiner]=and&property[0][property]=253&property[0][type]=res&property[0][text]=54151";
    $auteurs = fetchOmekaJson($omkApiUrl,$query);
    */
    $csvFile = "http://localhost/omk_paragraphe/files/bulk_export/csv-20260227-163101.csv";
    if (($handle = fopen($csvFile, "r")) !== FALSE) {
        $headers = fgetcsv($handle, 1000, ",",'"',"\\");
        while ($data = fgetcsv($handle, 1000, ",",'"',"\\")){
            $auteurs[] = $data[0];
        }
        fclose($handle);
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

        echo "Traitement : " . $auteur['o:title'] . "\n";
        $md = $generator->generate($auteur);
        
        //récupère le titre
        preg_match('/\*\*Titre\s*:\s*(.+?)\*\*/s', $md, $mTitre);
        if (!empty($mTitre[1])) {
            $titre = trim($mTitre[1]);
        }else{
            $titre = " --- ";
        }
        //ajoute un sous titre
        $md = str_replace("bibliography: referenceAuteurs.bib","subtitle:\"$titre\"\nbibliography: referenceAuteurs.bib\n\n",$md);

        $fileName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $auteur['o:title']))) . ".qmd";
        file_put_contents($outputFolder . DIRECTORY_SEPARATOR . $fileName, $md);
        $props[]=["auteur"=>$auteur['o:title'],"titre"=>$titre];
    }

    $program = new ProgrammeGenerator($props,$omkItem);
    $program->generate();

}

function fetchOmekaJson($baseUrl, $endpoint) {
    $url = rtrim($baseUrl, '/') . '/api/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
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