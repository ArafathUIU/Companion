<?php
require_once 'config/db.php';

function getUserProfileTags($user_id, $pdo) {
    $stmt = $pdo->prepare("SELECT question_number, score FROM user_scores WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $scores = $stmt->fetchAll();

    $tags = [];

    foreach ($scores as $row) {
        $q = (int)$row['question_number'];
        $score = (int)$row['score'];

        if ($score >= 7) {
            switch ($q) {
                case 1: $tags[] = 'stress'; break;
                case 2: $tags[] = 'anxiety'; break;
                case 3: $tags[] = 'depression'; break;
                case 4: $tags[] = 'anhedonia'; break;
                case 5: $tags[] = 'burnout'; break;
                case 6: $tags[] = 'overload'; break;
                case 7: $tags[] = 'sleep_issues'; break;
                case 8: $tags[] = 'self_esteem'; break;
            }
        }
    }

    return array_unique($tags);
}
?>
