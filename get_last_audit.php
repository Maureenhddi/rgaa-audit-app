<?php
require 'vendor/autoload.php';

use App\Kernel;

$kernel = new Kernel('dev', true);
$kernel->boot();

$em = $kernel->getContainer()->get('doctrine')->getManager();
$auditRepo = $em->getRepository('App\Entity\Audit');

$audits = $auditRepo->findBy([], ['createdAt' => 'DESC'], 1);

if (!empty($audits)) {
    $audit = $audits[0];

    echo "=== DERNIER AUDIT ===\n";
    echo "ID: " . $audit->getId() . "\n";
    echo "URL: " . $audit->getUrl() . "\n";
    echo "Date: " . $audit->getCreatedAt()->format('Y-m-d H:i:s') . "\n";
    echo "Nombre d'erreurs: " . count($audit->getIssues()) . "\n";

    // Compter les critères RGAA uniques
    $criteria = [];
    foreach ($audit->getIssues() as $issue) {
        $rgaa = $issue->getRgaaCriterion();
        if ($rgaa && !in_array($rgaa, $criteria)) {
            $criteria[] = $rgaa;
        }
    }

    echo "Critères RGAA détectés: " . count($criteria) . "\n";
    echo "Taux de conformité: " . number_format($audit->getConformityRate(), 1) . "%\n";
    echo "\n=== CRITÈRES DÉTECTÉS ===\n";
    sort($criteria);
    echo implode(', ', $criteria) . "\n";

} else {
    echo "Aucun audit trouvé dans la base de données\n";
}
