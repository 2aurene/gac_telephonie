<?php
require('inc/bdd_connection.php');

// Durée totale réelle des appels effectués après le 15/02/2012 (inclus)
$d_sql = "SELECT SUM(TIME_TO_SEC(duree_reel)) AS 'duree'
FROM operations
WHERE `date` > '2012-02-14'";
$d_res = $pdo->query($d_sql);
$d_data = $d_res->fetch();
$d_ttl = $d_data['duree'];
// Formatage en php car mysql limite à 838:59:59
$d_heu = intval($d_ttl / 3600);
$d_min = intval(($d_ttl - $d_heu * 3600) / 60);
$d_sec = $d_ttl - $d_heu * 3600 - $d_min * 60;

// TOP 10 des volumes data facturés en dehors de la tranche horaire 8h00-18h00, par abonné.
$t_sql = "SELECT abonne, SUM(volume_fact) AS data_volume
FROM operations
WHERE heure < '8:00' OR heure > '17:59'
GROUP BY abonne
ORDER BY data_volume DESC
LIMIT 0, 10";
$t_res = $pdo->query($t_sql);
$t_data = $t_res->fetchAll();

// Quantité totale de SMS envoyés par l'ensemble des abonnés
$s_sql = "SELECT COUNT(id) AS 'total sms'
FROM operations
WHERE `type` = 'SMS'";
$s_res = $pdo->query($s_sql);
$s_data = $s_res->fetch();

// #### [VUE] ####
include('inc/header.php');
?>
		<h1>Statistiques</h1>
		<fieldset>
			<legend>Durée totale réelle des appels effectués après le 15/02/2012 (inclus)</legend>
			<h3><?= $d_heu ?> Heures <?= $d_min ?> Minutes <?= $d_sec ?> Secondes</h3>
			<textarea><?= $d_sql ?></textarea>
		</fieldset>
		<br>
		<fieldset>
			<legend>TOP 10 des volumes data facturés en dehors de la tranche horaire 8h00-18h00, par abonné</legend>
			<br>
			<table>
				<tr>
					<th></th>
					<th>N° abonné</th>
					<th>Volume de data</th>
				</tr>
<?php foreach($t_data as $id => $line){ ?>
				<tr>
					<td><?= $id + 1 ?></td>
					<td><?= $line['abonne'] ?></td>
					<td><?= $line['data_volume'] ?></td>
				</tr>
<?php } ?>
			</table>
			<br>
			<textarea rows="8"><?= $t_sql ?></textarea>
		</fieldset>
		<br>
		<fieldset>
			<legend>Quantité totale de SMS envoyés par lʼensemble des abonnés</legend>
			<h3><?= $s_data['total sms'] ?> SMS</h3>
			<textarea><?= $s_sql ?></textarea>
		</fieldset>
<?php
include('inc/footer.php');
?>