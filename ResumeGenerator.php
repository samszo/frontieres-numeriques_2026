<?php
class ResumeGenerator {
    private $apiKey;
    //private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent";
    private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent";
    private $siteContext = "";// "Thème : Analogies & Technologies (7ème édition, Djerba, Tunisie). Axe 1: Bio-inspiration. Axe 2: Outil cognitif. Axe 3: Jumeaux numériques. Axe 4: Éthique.";
    private $siteBiblio = "";// "- Serres (1997, 2009)\n- Descola (2005)\n- Guattari (1992)\n- Citton (2010)\n- Hofstadter & Sander (2013)";
    private $siteAxes = "";// "- Serres (1997, 2009)\n- Descola (2005)\n- Guattari (1992)\n- Citton (2010)\n- Hofstadter & Sander (2013)";
    private $omkItemConf;
    private $omkSite;
    private $outputFolder;    
    private $bibFile;    

    public function __construct($apiKey,$context,$omkItemConf,$outputFolder,$omkSite) {
        $this->apiKey = $apiKey;
        $this->siteContext = isset($context['CONTEXTE']) ? implode(", ", $context['CONTEXTE']) : "no";
        $this->siteAxes = isset($context['AXES']) ? implode(", ", $context['AXES']) : "no";;
        $this->siteBiblio = isset($context['BIBLIO']) ? implode(", ", $context['BIBLIO']) : "no";
        $this->omkItemConf = $omkItemConf;
        $this->omkSite = $omkSite;
        $this->outputFolder = $outputFolder;
    }

    private function cleanAffiliations($orgas, $limit=10){
        $cleaned = []; 
        foreach ($orgas as $o) {
            if(count($cleaned) < $limit && $o["@annotation"] && $o["@annotation"]["curation:end"]){
                $orga = $o["display_title"];
                $end = $o["@annotation"]["curation:end"][0]["@value"];
                $dateEnd = strtotime($end ?? "9999-12-31");
                $cleaned[]=["orga"=>$orga,"end"=>$dateEnd];
            }
        }
        usort($cleaned, function($a, $b) {
                return $b["end"] <=> $a["end"]; // descending order (most recent first)
            });        
        return $cleaned;

    }

    private function cleanPublications($publis, $limit = 10) {
        $cleaned = []; 
        $publis = array_map(fn($p) => [...$p, '@rank' => (int)($p['@rank'] ?? 999)], $publis);
        usort($publis, fn($a, $b) => $a['@rank'] <=> $b['@rank']);
        foreach ($publis as $p) {
            if(count($cleaned) < $limit && $p["@annotation"] && $p["@annotation"]["foaf:status"] && $p["@annotation"]["foaf:status"][0]["@value"]=="author"){
                $ref = $p["@annotation"]["dcterms:isReferencedBy"][0]["@value"];
                if (strpos($ref, 'hal') === 0) {
                    $entry = $this->fetchBibtexFromHal($ref);
                } elseif (strpos($ref, '10.') === 0 || strpos($ref, 'doi') === 0) {
                    $entry = $this->fetchBibtexFromDoi($ref);
                } elseif (strpos($ref, 'sudoc') === 0) {
                    $entry = $this->fetchBibtexFromSudoc($ref);
                }
                if (isset($entry)) {
                    if (!$this->isDuplicate($entry)) {
                        // On n'ajoute que si la clé est unique
                        file_put_contents($this->bibFile, $entry . "\n\n", FILE_APPEND);
                        echo "✅ Ajoutée au .bib : " . $ref . "<br>";
                    } else {
                        echo "⏭️ Déjà présente (doublon ignoré) : " . $ref . "<br>";
                    }
                    // On conserve quand même l'entrée pour l'envoyer à l'IA 
                    // afin qu'elle puisse citer la clé même si elle était déjà dans le fichier
                    $cleaned[] = $entry;
                }else                
                    $cleaned[] = $p["@value"];

            }
        }
        return implode("\n- ", $cleaned);
    }

    /**
     * Vérifie si une entrée BibTeX existe déjà dans le fichier .bib
     */
    private function isDuplicate($newEntry) {

        $this->bibFile = $this->outputFolder . DIRECTORY_SEPARATOR . 'referenceAuteurs.bib';
        if (!file_exists($this->bibFile)){
            file_put_contents($this->bibFile, " ");
            return false;
        }

        // 1. Extraire la clé de la nouvelle entrée (ex: @article{CLE, ...)
        // Regex : cherche ce qui est entre le premier '{' et la première ','
        if (preg_match('/^@[^{]+\{([^,]+),/m', $newEntry, $matches)) {
            $newKey = trim($matches[1]);
            
            // 2. Lire le fichier existant
            $currentBib = file_get_contents($this->bibFile);
            
            // 3. Chercher si la clé existe déjà dans le fichier
            // On cherche "@...{newKey," pour éviter les correspondances partielles
            return (strpos($currentBib, "{" . $newKey . ",") !== false);
        }
        
        return false;
    }

    private function fetchBibtexFromHal($halId) {
        // Nettoyage de l'ID (ex: hal-03175210 -> 03175210)
        $id = str_replace('halhal','hal',$halId);
        
        // URL de l'API HAL pour l'export BibTeX
        $url = "https://api.archives-ouvertes.fr/search/?q=".$id."&wt=bibtex";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // On demande explicitement du BibTeX via les headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/x-bibtex'
        ]);

        $bibtex = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ($status == 200) ? trim($bibtex) : false;

    }
    private function fetchBibtexFromDoi($doi) {
        // Nettoyage : on s'assure que le DOI ne contient pas le préfixe https://doi.org/
        $doi = str_replace(['https://doi.org/', 'doi:'], '', $doi);
        $url = "https://doi.org/" . trim($doi);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // On demande explicitement du BibTeX via les headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/x-bibtex'
        ]);

        $bibtex = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ($status == 200) ? trim($bibtex) : false;
    }

    private function fetchBibtexFromSudoc($ref) {
        $id = str_replace('sudoc', '', $ref);
        $url = "https://www.sudoc.fr/export/q=ppn&v=".$id."&f=bibtex";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // On demande explicitement du BibTeX via les headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/x-bibtex'
        ]);

        $bibtex = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ($status == 200) ? trim($bibtex) : false;
    }

    private function cleanMotsclefs($motsclefs, $limit = 20) {
        $cleaned = []; 
        foreach ($motsclefs as $mc) {
            if($mc["display_title"])
                $cleaned[] = $mc["display_title"];
            if(count($cleaned)==$limit) return implode("\n- ", $cleaned);
        }
        return implode("\n- ", $cleaned);
    }


    public function generate($auteur) {

        $fileNamePrompt = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', 'prompt_'.$auteur['o:title']))) . ".txt";
        if (!file_exists($this->outputFolder . DIRECTORY_SEPARATOR . $fileNamePrompt)){
            if(!isset($auteur["foaf:publications"]) || !isset($auteur["foaf:publications"])){
                echo "Vous devez associé aux données Scanr la personne :".$auteur['o:title'] . "<br>";
                return false;
            }
            $pubs = $this->cleanPublications($auteur["foaf:publications"]);
            $motsclefs = $this->cleanMotsclefs($auteur["dcterms:subject"]);
            $systemPrompt = "Tu es un curateur scientifique pour {$this->omkItemConf['o:title']}. CONTEXTE: {$this->siteContext}. AXES: {$this->siteAxes}. BIBLIO: {$this->siteBiblio}. 
                MISSION: 1. YAML Quarto (title, categories). 2. Résumé en citant les références via leurs clés BibTeX (3-4 lignes). 3. Proposition d'intervention dans la conférence avec un titre et un résumé de 10 lignes. 4. Résonances Bibliographiques en croisant la bibliographie de la conférence et celle de l'auteur et en les citant via leurs clés BibTeX. 
                STRUCTURE DE SORTIE :
                    ---
                    title: [titre de la proposition]
                    author: AUTEUR
                    categories: TAGS

                    bibliography: referenceAuteurs.bib

                    ---
                    
                    #### Nom
                    
                    ::: {.callout-note appearance='minimal'}
                    **Résumé :** [3-4 lignes]
                    :::

                    ### Proposition
                    
                    **Titre : [titre de la proposition]**
                    
                    **Résumé :**
                    
                    [Résumé de la proposition]
                    
                    ### Résonances Bibliographiques
                    [Lier les travaux de l'auteur à la bibliographie pivot en utilisant les balises de citation @].";


            $data = ["contents" => [["parts" => [["text" => $systemPrompt . "\n\nAUTEUR:". $auteur["o:title"]." \nPUBS: $pubs\nTAGS: $motsclefs"]]]]];
            file_put_contents($this->outputFolder . DIRECTORY_SEPARATOR . $fileNamePrompt, json_encode($data));
        }else{
            $data = json_decode(file_get_contents($this->outputFolder . DIRECTORY_SEPARATOR . $fileNamePrompt));            
        }

        $fileName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', 'resume_'.$auteur['o:title']))) . ".json";
        if (!file_exists($this->outputFolder . DIRECTORY_SEPARATOR . $fileName)){
            $ch = curl_init($this->apiUrl . "?key=" . $this->apiKey);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $response = curl_exec($ch);
            file_put_contents($this->outputFolder . DIRECTORY_SEPARATOR . $fileName, $response);
        }else $response = file_get_contents($this->outputFolder . DIRECTORY_SEPARATOR . $fileName);  

        $result = json_decode($response, true);
        $md = $result['candidates'][0]['content']['parts'][0]['text'] ?? "Erreur.";

        //ajustement du qmd
        $socialLinks = "";
        foreach ($auteur["dcterms:isReferencedBy"] as $ref) {
            $socialLinks .= "[{{< fa solid globe >}} ".$ref['@id']."](".$ref['@id'].") ";
        }
        if (!empty($socialLinks)) {
            $md = preg_replace('/(#### .*?\n)/', "$1$socialLinks\n\n", $md);
        }
        //ajoute les affiliations
        $orgas = $this->cleanAffiliations($auteur["labo:hasOrga"]);
        if(count($orgas))
            $md = str_replace("#### ".$auteur["o:title"],"#### ".$orgas[0]["orga"],$md);
        else
            $md = str_replace("#### ".$auteur["o:title"],"",$md);

        //ajoute les références du Prompt
        $dataLink = str_replace("api-context","s/".$this->omkSite["o:slug"],$this->omkSite["@context"])."/item/".$auteur["o:id"];
        $md = str_replace("### Proposition","### Proposition générée\n\n  - [lien vers les données]($dataLink)\n  - [lien vers le prompt]($fileNamePrompt)\n  - [lien vers la réponse]($fileName)\n\n",$md);
        
        return $md;
    }
}
?>