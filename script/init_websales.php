<?php
// Load Dolibarr environment
$res = @include '../../main.inc.php';  // Main Dolibarr environment importation
if (! $res) {
	$res = @include '../../../main.inc.php';  // For another relative directory (custom or extension)
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception;

// Initialize user and language
$langs->load("dolistorextract@dolistorextract");

// Display header
llxHeader('', $langs->trans("ImportCSVData"));

// Check if file has been uploaded
if (isset($_FILES['importfile']) && $_FILES['importfile']['error'] == UPLOAD_ERR_OK) {
	// Process the uploaded file
	$fileTmpPath = $_FILES['importfile']['tmp_name'];
	$fileName = $_FILES['importfile']['name'];
	$fileSize = $_FILES['importfile']['size'];
	$fileType = $_FILES['importfile']['type'];

	// Check file extension
	$allowedExtensions = ['xlsx'];
	$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

	if (in_array($fileExtension, $allowedExtensions)) {
		try {
			// Load the spreadsheet file
			$spreadsheet = IOFactory::load($fileTmpPath);
			$worksheet = $spreadsheet->getActiveSheet();
			$data = $worksheet->toArray();

			// Skip the header row
			$header = array_shift($data);

			// Iterate over each row and process the data
			foreach ($data as $row) {
				$TItemDatas = [
					'item_reference' => $row[8], // Product ref
					'item_price' => $row[9], // Amount earned
					'item_quantity' => 1, // Default value
					// You can add other fields if needed
				];

				$socid = 1; // Replace with actual company ID
				$result = addWebmoduleSales($TItemDatas, $socid);

				if ($result <= 0) {
					echo '<div class="alert alert-danger" role="alert">';
					echo $langs->trans("FailedToInsertDataFor") . ' ' . htmlspecialchars($TItemDatas['item_reference']);
					echo '</div>';
				}
			}

			echo '<div class="alert alert-success" role="alert">';
			echo $langs->trans("FileProcessedSuccessfully");
			echo '</div>';
		} catch (Exception $e) {
			echo '<div class="alert alert-danger" role="alert">';
			echo $langs->trans("FileProcessingError") . ': ' . $e->getMessage();
			echo '</div>';
		}
	} else {
		echo '<div class="alert alert-warning" role="alert">';
		echo $langs->trans("InvalidFileType");
		echo '</div>';
	}
}

// Display the form with improved style
// Display the form with improved inline styles
echo '<div style="max-width: 800px; margin: 40px auto; padding: 30px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9;">';
echo '<div style="margin-bottom: 30px;">';
echo '<h1 style="font-size: 28px; color: #333; margin: 0;">'.$langs->trans("ImportCSVData").'</h1>';
echo '</div>';
echo '<div style="padding: 20px 0;">';
echo '<p>'.$langs->transnoentitiesnoconv("ImportExcelDescription").'</p>'; // Add a description for clarity

echo '<form enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'" method="POST" style="display: flex; flex-direction: column; gap: 20px;">';
echo '<div style="margin-bottom: 20px;">';
echo '<label for="importfile" style="font-weight: bold; display: block; margin-bottom: 10px; color: #555;">'.$langs->trans("SelectFile").'</label>';
echo '<input type="file" name="importfile" id="importfile" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px;" required>';
echo '</div>';
echo '<div>';
echo '<button type="submit" style="background-color: #007bff; color: white; border: none; padding: 12px 20px; font-size: 16px; border-radius: 4px; cursor: pointer; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">'.$langs->trans("Import").'</button>';
echo '</div>';
echo '</form>';
echo '</div>'; // End content
echo '</div>'; // End container



// Page footer
llxFooter();

// Close database handler
$db->close();
?>
