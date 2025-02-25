<?php
// Chargement de l'environnement Dolibarr
$res = @include '../../main.inc.php';  // Importation principale de l'environnement Dolibarr
if (! $res) {
	$res = @include '../../../main.inc.php';  // Si le premier chemin échoue, on essaie avec un autre répertoire relatif (custom ou extension)
}
require_once __DIR__ . '/../class/actions_dolistorextract.class.php';
set_time_limit(0);
// Initialisation de l'objet pour gérer les actions liées à Dolistore
$actionsDolistore = new ActionsDolistorextract($db);
$form = new Form($db);
// Initialisation de la langue pour l'interface utilisateur
$langs->load("dolistorextract@dolistorextract");

// Affichage de l'en-tête
llxHeader('', $langs->transnoentitiesnoconv("ImportCSVData"));

$error = 0; // Initialisation du compteur d'erreurs

// Vérification si un fichier a été téléchargé
if (isset($_FILES['importfile']) && $_FILES['importfile']['error'] == UPLOAD_ERR_OK) {
	// Démarrage de la transaction
	$db->begin();
	// Traitement du fichier uploadé
	$fileTmpPath = $_FILES['importfile']['tmp_name'];
	$fileName = $_FILES['importfile']['name'];
	$fileSize = $_FILES['importfile']['size'];
	$fileType = $_FILES['importfile']['type'];

	// Vérification de l'extension du fichier
	$allowedExtensions = ['csv'];
	$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

	$fk_company = GETPOST('select_company', 'int');

	if (in_array($fileExtension, $allowedExtensions)) {
		// Ouverture du fichier CSV
		if (($handle = fopen($fileTmpPath, 'r')) !== FALSE) {
			// Ignorer la première ligne (en-tête)
			$header = fgetcsv($handle, 1000, ",");

			// Parcours de chaque ligne du fichier CSV
			while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
				$actionsDolistore->logCat = ''; // Réinitialiser la log pour chaque entrée

				// Si l'email est vide, on saute cette ligne
				if (empty($row[3])) continue;

				// Construction des données de l'élément à partir du CSV
				$TItemDatas = [
					'item_name' => $row[7],      // Nom du produit
					'item_reference' => $row[8], // Référence Dolistore
					'item_price' => $row[9],     // Montant gagné
					'item_quantity' => 1,        // Quantité par défaut
					'date_sale' => strtotime($row[5]) // Date de la vente convertie en timestamp
				];

				// Si l'article est remboursé ou annulé, définir le prix à 0 et marquer comme remboursé
				if ($TItemDatas['item_price'] == 'RefundedOrCancelled') {
					$TItemDatas['item_price'] = 0;
					$TItemDatas['item_refunded'] = 1;
				}
				// Exécution de la requête SQL pour récupérer l'ID de la société basée sur l'email
				$sql = 'SELECT s.rowid FROM ' . $db->prefix() . 'societe as s WHERE email = "' . $db->escape($row[3]) . '"';
				$resql = $db->query($sql);
				//On vérifie s'il y a pas un contact avec cette adresse mail si on ne trouve pas la société
				if ($resql && $db->num_rows($resql) == 0) {
					// Recherche d'un contact ayant cet email
					$sql = 'SELECT s.fk_soc as rowid FROM ' . $db->prefix() . 'socpeople as s WHERE email = "' . $db->escape($row[3]) . '"';
					$resql = $db->query($sql);
					// Si aucun contact n'est trouvé, on procède à une recherche par domaine
					if ($resql && $db->num_rows($resql) == 0) {
						// Extraction du domaine à partir de l'email
						$domain = getDomainFromEmail($row[3]);

						if (isGenericDomain($domain) && empty($fk_company)) {
							// Ignorer les domaines génériques
							//Si pas de tiers sélectionné on affiche l'erreur
							$error++;
							echo displayStyledMessage($langs->transnoentitiesnoconv("GenericDomainFoundFor", $row[3]) . ', ' . $row[5], "error");
							continue;
						}

						// Recherche par domaine d'email dans les sociétés
						$sql = 'SELECT s.rowid FROM ' . $db->prefix() . 'societe as s WHERE s.email LIKE "%@' . $domain . '%"';
						$resql = $db->query($sql);
						if ($resql) {
							// Si plusieurs sociétés sont trouvées, on affiche un message d'erreur
							if ($db->num_rows($resql) > 1 && empty($fk_company)) {
								$error++;

								echo displayStyledMessage($langs->transnoentitiesnoconv("ManyThirdpartiesFound", $row[3]).', '.$row[5], "error");
								continue;
								// Si aucune société n'est trouvée, on recherche dans les contacts
							} else if ($db->num_rows($resql) == 0 && empty($fk_company)) {
								// Si plusieurs sociétés sont trouvés, on affiche un message d'erreur
								$sql = 'SELECT DISTINCT s.fk_soc as rowid FROM ' . $db->prefix() . 'socpeople as s WHERE s.email LIKE "%@' . $domain . '%"';
								$resql = $db->query($sql);
								if ($resql && $db->num_rows($resql) > 1) {
									$error++;
									echo displayStyledMessage($langs->transnoentitiesnoconv("ManyThirdpartiesFound", $row[3]).', '.$row[5], "error");
									continue;
								}
							}
						}
					}
				}

				if (! ($resql && $db->num_rows($resql) > 0) && empty($fk_company)) {
					// Si la société n'est pas trouvée, incrémenter l'erreur et afficher un message d'erreur
					$error++;
					echo displayStyledMessage($langs->transnoentitiesnoconv("FailedToGetSocieteFor") . ' ' . $row[3].', '.$row[5], "error");
				} else {
					if ($resql && $db->num_rows($resql) > 0) {
						// Si la société est trouvée, récupérer son ID et insérer les données de vente
						$obj = $db->fetch_object($resql);
						$socid = $obj->rowid; // Récupération de l'ID de la société
					} else $socid = $fk_company;

					if(isModEnabled("webhost")) {
						$result = $actionsDolistore->addWebmoduleSales($TItemDatas, $socid);

						if ($result <= 0) {
							// Si l'insertion échoue, incrémenter l'erreur et afficher un message d'erreur
							$error++;
							echo displayStyledMessage($langs->transnoentitiesnoconv("FailedToInsertDataFor") . ' ' . htmlspecialchars($TItemDatas['item_reference'])  . $actionsDolistore->logCat.', '.$row[5], "error");
						}
					}
				}
			}

			// Fermer le fichier une fois le traitement terminé
			fclose($handle);
		} else {
			// Affichage d'un message d'erreur si le fichier ne peut pas être ouvert
			$error++;
			echo displayStyledMessage($langs->transnoentitiesnoconv("FileProcessingError"), "error");
		}
	} else {
		// Affichage d'un message d'erreur si le type de fichier n'est pas valide
		$error++;
		echo displayStyledMessage($langs->transnoentitiesnoconv("InvalidFileType"), "error");
	}
	if ($error > 0) {
		echo displayStyledMessage($langs->transnoentitiesnoconv("RollbackCauseOfErrors", $error), "error");
		$db->rollback();
	} else {
		// Si tout s'est bien passé, afficher un message de succès et valider la transaction
		echo displayStyledMessage($langs->transnoentitiesnoconv("FileProcessedSuccessfully"), "success");
		$db->commit();
	}
}

// Si des erreurs sont détectées, afficher un message et annuler la transaction

// Affichage du formulaire d'import
echo '<div style="max-width: 800px; margin: 40px auto; padding: 30px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9;">';
echo '<div style="margin-bottom: 30px;">';
echo '<h1 style="font-size: 28px; color: #333; margin: 0;">' . $langs->transnoentitiesnoconv("ImportCSVData") . '</h1>';
echo '</div>';
echo '<div style="padding: 20px 0;">';
echo '<p>' . $langs->transnoentitiesnoconv("ImportCSVDescription") . '</p>'; // Description du formulaire

// Affichage du formulaire pour le fichier à importer
echo '<form enctype="multipart/form-data" action="' . $_SERVER['PHP_SELF'] . '" method="POST" style="display: flex; flex-direction: column; gap: 20px;">';
echo '<div style="margin-bottom: 20px;">';
echo '<label for="importfile" style="font-weight: bold; display: block; margin-bottom: 10px; color: #555;">' . $langs->transnoentitiesnoconv("SelectFile") . '</label>';
echo '<input type="file" name="importfile" id="importfile" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px;" required>';
echo '<input type="hidden" name="token" value="' . newToken() . '">';
echo '</div>';
// Encadré pour le champ de sélection d'entreprise
echo '<div style="margin-bottom: 20px;">';
echo '<label for="select_company" style="font-weight: bold; display: block; margin-bottom: 10px; color: #555;">' . $langs->transnoentitiesnoconv("SelectCompanyInitWebsale") . '</label>';
echo '<div style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px;">';
echo $form->select_company('', 'select_company', '', 1, 0, 0,  array(),  0, 'allwidth',  'style="width:100%"'); // Champ select d'entreprise
echo '</div>';
echo '</div>';

echo '<div>';
echo '<button type="submit" style="background-color: #007bff; color: white; border: none; padding: 12px 20px; font-size: 16px; border-radius: 4px; cursor: pointer; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">' . $langs->transnoentitiesnoconv("Import") . '</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>'; // Fin du formulaire

// Pied de page
llxFooter();

// Fermeture de la connexion à la base de données
$db->close();

/**
 * Extrait le domaine d'un email.
 *
 * @param string $email L'adresse email à traiter.
 * @return string Le domaine extrait de l'email.
 */
function getDomainFromEmail(string $email): string {
	$parts = explode('@', $email);
	if (count($parts) == 2) {
		$domain = explode('.', $parts[1]);

		return $domain[0];
	}

	return '';
}

/**
 * Vérifie si le domaine est un domaine générique.
 *
 * @param string $domain Le domaine à vérifier.
 * @return bool True si le domaine est générique, sinon false.
 */
function isGenericDomain(string $domain): bool {
	$genericDomains = [
		'gmail', 'free', 'hotmail', 'outlook', 'office', 'office365', 'ovh', 'yahoo', 'mailjet',
		'aol', 'icloud', 'mail', 'zoho', 'inbox', 'gmx', 'webmail', 't-online', 'yandex', 'mail.ru',
		'live', 'mail.com', 'protonmail', 'fastmail', 'hushmail', 'runbox', 'gmane', 'tutanota',
		'outlook365', 'mailinator', 'maildrop', 'temporary', 'discard', 'sharklasers', 'guerrillamail',
		'10minutemail', 'trashmail', 'yopmail', 'fakeinbox', 'meltmail', 'temp-mail', 'laposte', 'me'
	];

	return in_array($domain, $genericDomains);
}

/**
 * Affiche un message stylé.
 *
 * @param string $message Le message à afficher.
 * @param string $type Le type de message ('error', 'success', etc.).
 * @return string Le message formaté.
 */
function displayStyledMessage(string $message, string $type = 'error'): string {
	$color = $type === 'error' ? '#721c24' : '#155724';
	$background = $type === 'error' ? '#f8d7da' : '#d4edda';
	$border = $type === 'error' ? '#f5c6cb' : '#c3e6cb';

	return '<div style="color: ' . $color . '; background-color: ' . $background . '; border-color: ' . $border . '; padding: 10px; border-radius: 5px; margin-bottom: 15px;">'
		. htmlspecialchars($message) . '</div>';
}

?>
