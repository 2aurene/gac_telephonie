<?php
session_start();

// réinitialisation formulaire
if(isset($_POST['reset_file'])){
	session_destroy();
	session_start();
}

// chargement session
$file_path = isset($_SESSION['file_path']) ? $_SESSION['file_path'] : null;

// initialisation des variables
$display_file_form = is_null($file_path) ? true : false;
$file_form_errors = array();
$display_bdd_confirmation = true;

$data = array();
$data_count = 0;
$import_errors = array();
$import_errors_count = 0;

const TYPE_SMS = 'SMS';
const TYPE_CALL = 'APPEL';
const TYPE_CONNECTION = 'CONNEXION';

$decimal_test = '/^[0-9]*\.[0-9]*$/';
$time_test = '/^[0-9]{2}\:[0-9]{2}\:[0-9]{2}$/';

$upload_directory = 'upload/';
$file_authorized_ext = ['csv'];
$file_maxsize = 10;

$max_nb_insert = 1000;

// #### [ TRAITEMENT DU FORMULAIRE ] ####
if(isset($_POST['submit_import'])){
	if(!isset($_FILES['operations']) OR $_FILES['operations']['error'] > 0){
		$file_form_errors[] = 'Le fichier nʼa pu être téléchargé.';
	} else {
		$file_data = $_FILES['operations'];
		// vérification de la taille du fichier
		if($file_data['size'] > ($file_maxsize * 1024 * 1024)){
			$file_form_errors[] = 'Le fichier fait plus de '.$file_maxsize.'Mo';
		} else {
			// vérification de l'extension
			$point_position = strrpos($file_data['name'], '.');
			$file_ext = substr($file_data['name'], $point_position + 1);
			if(!in_array($file_ext, $file_authorized_ext)){
				$file_form_errors[] = 'Lʼextension du fichier nʼest pas authorisée.';
			} else {
				// déplacement du fichier
				$file_name = substr($file_data['name'], 0, $point_position);
				$destination = $upload_directory.$file_name.'_'.date('YmdHi').'.'.$file_ext;
				move_uploaded_file($file_data['tmp_name'], $destination);
				
				$file_path = $destination;
				$_SESSION['file_path'] = $file_path;
				$display_file_form = false;
			}
		}
	}
}

// #### [ ANALYSE DU FICHIER ] ####
if(isset($file_path)){
	// ouverture du fichier
	$file = fopen($file_path, 'r');
	// on passe les 3 premières lignes
	for($nline=0 ; $nline <= 4 ; $nline++){
		$current_line = fgets($file);
	}
	// lecture du fichier
	while (!feof($file)) {
		// extraction des données
		$line_data = explode(';', $current_line);
		$error = false;
		$err_desc = array();
		
		// numéro abonné
		$sub = $line_data[2];
		if(is_numeric($sub)){
			$sub = intval($sub);
		} else {
			$error = true;
			$err_desc[] = 'Le numéro dʼabonné est invalide.';
		}
		
		// date et heure
		$date = $line_data[3];
		$hour = $line_data[4];
		$date_hour = $date.' '.(!preg_match($time_test, $hour) ? '00:00:00' : $hour);
		$datetime = date_create_from_format('d/m/Y H:i:s', $date_hour);
		if($datetime === false){
			$error = true;
			$err_desc[] = 'La date est invalide.';
		}
		
		// type + volume/durée
		$vol_r = $line_data[5];
		$vol_f = $line_data[6];
		$time_r = $time_f = null;
		
		if(empty($vol_r)){
			if(empty($vol_f)){
				$type = TYPE_SMS;
				$vol_r = $vol_f = null;
			} else {
				$error = true;
				$err_desc[] = 'Le format de "Durée/volume facturé" ne correspond pas au format de "Durée/volume réel" ('.TYPE_SMS.').';
			}
		} elseif(preg_match($decimal_test, $vol_r)){
			if(preg_match($decimal_test, $vol_f)){
				$type = TYPE_CONNECTION;
			} else {
				$error = true;
				$err_desc[] = 'Le format de "Durée/volume facturé" ne correspond pas au format de "Durée/volume réel" ('.TYPE_CONNECTION.').';
			}
		} elseif(preg_match($time_test, $vol_r)){
			if(preg_match($time_test, $vol_f)){
				$type = TYPE_CALL;
				$time_r = $vol_r;
				$time_f = $vol_f;
				$vol_r = $vol_f = null;
			} else {
				$error = true;
				$err_desc[] = 'Le format de "Durée/volume facturé" ne correspond pas au format de "Durée/volume réel" ('.TYPE_CALL.').';
			}
		} else {
			$error = true;
			$err_desc[] = 'Le type dʼopération nʼa pu être défini.';
		}
		
		// Insertion dans $data
		if(!$error){
			$data[] = [ 
				'sub' => $sub, 
				'datetime' => $datetime, 
				'type' => $type, 
				'time_r' => $time_r,
				'time_f' => $time_f,
				'vol_r' => $vol_r,
				'vol_f' => $vol_f
			];
			$data_count++;
		} else {
			$import_errors[] = [ $nline, $err_desc];
			$import_errors_count++;
		}
		
		// Ligne suivante
		$current_line = fgets($file);
		$nline++;
	}
	
	fclose($file);
}

// #### [ ENREGISTREMENT EN BDD ] ####
if(isset($_POST['submit_bdd_save'])){
	$display_file_form = false;
	$display_bdd_confirmation = false;
	if(!empty($data)){
		// connexion base
		require('inc/bdd_connection.php');
		
		$base_sql = "INSERT INTO `operations` (`abonne`, `date`, `heure`, `type`, `duree_reel`, `duree_fact`, `volume_reel`, `volume_fact`) VALUES ";
		$all_requests = '';
		$req_nb = 0;
		$res_nb = 0;
		$i = 0;
		
		while($i < $data_count){
			$j = 0;
			$sql = $base_sql;
			do {
				$line = $data[$i];
				
				$value  = "\r\n( ".$line['sub']; // abonne
				$value .= ", '".$line['datetime']->format('Y-m-d')."'"; // date
				$value .= ", '".$line['datetime']->format('H:i:s')."'"; // heure
				$value .= ", '".$line['type']."'"; // type
				$value .= ", ".(isset($line['time_r']) ? "'".$line['time_r']."'" : "NULL"); // duree_reel
				$value .= ", ".(isset($line['time_f']) ? "'".$line['time_f']."'" : "NULL"); // duree_fact
				$value .= ", ".(isset($line['vol_r']) ? $line['vol_r'] : "NULL"); // volume_reel
				$value .= ", ".(isset($line['vol_f']) ? $line['vol_f'] : "NULL"); // volume_fact
				$value .= ")";
			
				if($j !== $max_nb_insert - 1 AND $i !== $data_count - 1){ // si pas la dernière ligne
					$value .= ",";
				}
				
				$sql .= $value;
				$j++;
				$i++;
			} while ($j < $max_nb_insert && isset($data[$i]));
			
			$req = $pdo->prepare($sql);
			$res = $req->execute();
			$all_requests .= $sql.";\r\n\r\n";
			$req_nb++;
			$res_nb += $req->rowCount();
		}
		
		session_destroy();
	}
}

// #### [VUE] ####
include('inc/header.php');
?>
		<h1>Importer un fichier de tickets dʼappels</h1>
		<fieldset>
			<legend>Envoyer le fichier</legend>
			<p>
				- Maximum <?= $file_maxsize?>Mo<br>
				- Extensions authorisées :
<?php
foreach($file_authorized_ext as $ext){
	echo ' .'.$ext;
}
?>
			</p>
<?php 
if($display_file_form){
	if(!empty($file_form_errors)){
?>
			<p>
				Des erreurs sont survenues. Veuillez réessayer.<br>
<?php
		foreach($file_form_errors as $err){
			echo '- '.$err.'</br>';
		}
?>
			</p>
<?php
	}
?>
			<form method="post" action="" enctype="multipart/form-data">
				<input type="file" name="operations" /><br />
				<button type="submit" name="submit_import">Valider</button>
			</form>
<?php 
} else {
?>
			<p>Fichier téléchargé avec succès : <?= $file_path ?></p>
		</fieldset>
		<br>
		<fieldset>
			<legend>Analyse du fichier</legend>
			<p>
				- <b><?= $data_count ?></b> opérations enregistrables en base.<br>
				- <?= $import_errors_count ?> erreur(s) survenue(s).
			</p>
<?php
	if(!empty($import_errors)){
?>
			<p><textarea readonly><?php
		foreach($import_errors as $err){
			echo 'Ligne '.$err[0].' : '.implode(' ', $err[1])."\r\n";
		}
			?></textarea></p>
<?php
	}
	
	if($display_bdd_confirmation){
?>
			<form method="post" action="">
				<button type="submit" name="submit_bdd_save">Confirmer lʼinsertion en base</button>
				<button type="submit" name="reset_file">Recharger un fichier</button>
			</form>
<?php
	} else {
?>
		</fieldset>
		<br>
		<fieldset>
			<legend>Insertion en base</legend>
			<p>
				- <?= $req_nb ?> requête(s) exécutée(s).<br>
				- <b><?= $res_nb ?></b> ligne(s) insérée(s).
			</p>
			<p><textarea readonly rows="12"><?= $all_requests ?></textarea></p>
			<form method="post" action="">
				<button type="submit" name="reset_file">Charger un nouveau fichier</button>
			</form>
<?php
	}
}
?>
		</fieldset>
<?php
include('inc/footer.php');
?>