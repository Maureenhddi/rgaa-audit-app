<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Kernel;

$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();

// Get user ID 1
$user = $em->getRepository(\App\Entity\User::class)->find(1);

if (!$user) {
    die("User not found!");
}

echo "<h1>Test Action Plans Query</h1>";
echo "<p>User: " . htmlspecialchars($user->getName()) . " (ID: " . $user->getId() . ")</p>";

// Test query
$repo = $em->getRepository(\App\Entity\ActionPlan::class);
$qb = $repo->createQueryBuilder('ap')
    ->addSelect('c', 'p', 'items')
    ->join('ap.campaign', 'c')
    ->join('c.project', 'p')
    ->leftJoin('ap.items', 'items')
    ->where('p.user = :user')
    ->setParameter('user', $user)
    ->orderBy('ap.createdAt', 'DESC');

echo "<h2>Query</h2>";
echo "<pre>" . htmlspecialchars($qb->getDQL()) . "</pre>";

$actionPlans = $qb->getQuery()->getResult();

echo "<h2>Results</h2>";
echo "<p>Count: " . count($actionPlans) . "</p>";

if (count($actionPlans) > 0) {
    echo "<ul>";
    foreach ($actionPlans as $plan) {
        echo "<li>" . htmlspecialchars($plan->getName()) . " (ID: " . $plan->getId() . ")</li>";
        echo "<ul>";
        echo "<li>Campaign: " . htmlspecialchars($plan->getCampaign()->getName()) . "</li>";
        echo "<li>Project: " . htmlspecialchars($plan->getCampaign()->getProject()->getName()) . "</li>";
        echo "<li>Items: " . count($plan->getItems()) . "</li>";
        echo "</ul>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: red;'>No action plans found!</p>";
}
