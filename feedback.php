<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$error = '';
$notice = (string) ($_SESSION['feedback_notice'] ?? '');
unset($_SESSION['feedback_notice']);
$reviewerName = is_string($_POST['reviewer_name'] ?? null) ? trim($_POST['reviewer_name']) : '';
$reviewText = is_string($_POST['review_text'] ?? null) ? trim($_POST['review_text']) : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'moderate-feedback') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('You do not have permission to moderate feedback.');
        }
        $feedbackId = filter_var($_POST['feedback_id'] ?? null, FILTER_VALIDATE_INT);
        $visibility = $_POST['is_visible'] ?? null;
        if (!$feedbackId || $feedbackId < 1 || !in_array($visibility, ['0', '1'], true)) {
            $error = 'Invalid feedback update.';
        } else {
            $statement = $pdo->prepare('UPDATE customer_feedback SET is_visible = ? WHERE id = ?');
            $statement->execute([(int) $visibility, $feedbackId]);
            $_SESSION['feedback_notice'] = (int) $visibility === 1 ? 'Review is now visible.' : 'Review has been hidden.';
            header('Location: feedback.php');
            exit;
        }
    } elseif ($action === 'submit-feedback') {
        $category = $_POST['category'] ?? '';
        $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($category, ['restaurant', 'website'], true)) {
            $error = 'Choose whether your feedback is about the restaurant or website.';
        } elseif ($rating === false || $rating < 1 || $rating > 5) {
            $error = 'Choose a rating from 1 to 5 stars.';
        } elseif (strlen($reviewerName) > 120 || strlen($reviewText) > 2000) {
            $error = 'Your name must be 120 characters or fewer and your review 2,000 characters or fewer.';
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO customer_feedback (category, rating, reviewer_name, review_text) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([
                $category,
                $rating,
                $reviewerName === '' ? null : $reviewerName,
                $reviewText === '' ? null : $reviewText,
            ]);
            $_SESSION['feedback_notice'] = 'Thank you! Your review is now published.';
            header('Location: feedback.php');
            exit;
        }
    } else {
        $error = 'Unknown feedback action.';
    }
}

$averages = $pdo->query(
    'SELECT category, COUNT(*) AS review_count, AVG(rating) AS average_rating
     FROM customer_feedback WHERE is_visible = 1 GROUP BY category'
)->fetchAll();
$ratingsSummary = [];
foreach ($averages as $summary) {
    $ratingsSummary[$summary['category']] = $summary;
}
$statement = $pdo->prepare(
    'SELECT id, category, rating, reviewer_name, review_text, is_visible, created_at
     FROM customer_feedback' . ($isAdmin ? '' : ' WHERE is_visible = 1') . '
     ORDER BY created_at DESC LIMIT 100'
);
$statement->execute();
$reviews = $statement->fetchAll();
$categoryLabels = ['restaurant' => 'Restaurant', 'website' => 'Website / system'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ratings &amp; reviews | La Guerta Kuzina</title>
    <link href="app.css" rel="stylesheet">
</head>
<body class="feedback-page">
<?php require __DIR__ . '/nav.php'; ?>
<main class="feedback-main">
    <header class="feedback-hero">
        <span class="storefront-eyebrow">YOUR WORDS MATTER</span>
        <h1>A little love<br><span>goes a long way.</span></h1>
        <p>Tell us about your visit or your experience with our website. Your feedback helps us make every visit better.</p>
        <div class="feedback-hero-mark" aria-hidden="true">“</div>
    </header>
    <?php if ($error !== ''): ?><div class="alert alert-danger feedback-alert" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success feedback-alert" role="status"><?= h($notice) ?></div><?php endif; ?>

    <div class="row g-4 feedback-content">
        <section class="col-lg-5">
            <div class="card feedback-form-card"><div class="card-body">
                <span class="storefront-eyebrow">WE’RE LISTENING</span>
                <h2>Share your experience</h2>
                <p class="feedback-form-intro">It only takes a moment, and it means a lot to us.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="submit-feedback">
                    <div class="mb-3 feedback-field">
                        <label class="form-label" for="category">What is your feedback about?</label>
                        <select class="form-select" id="category" name="category" required>
                            <option value="restaurant">Restaurant</option>
                            <option value="website">Website / system</option>
                        </select>
                    </div>
                    <div class="mb-3 feedback-field">
                        <label class="form-label" for="rating">Rating</label>
                        <select class="form-select" id="rating" name="rating" required>
                            <option value="">Choose a rating</option>
                            <?php for ($stars = 5; $stars >= 1; $stars--): ?>
                                <option value="<?= $stars ?>"><?= $stars ?> <?= $stars === 1 ? 'star' : 'stars' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="mb-3 feedback-field">
                        <label class="form-label" for="reviewer_name">Your name (optional)</label>
                        <input class="form-control" id="reviewer_name" name="reviewer_name" maxlength="120" value="<?= h($reviewerName) ?>">
                    </div>
                    <div class="mb-4 feedback-field">
                        <label class="form-label" for="review_text">Review (optional)</label>
                        <textarea class="form-control" id="review_text" name="review_text" rows="4" maxlength="2000"><?= h($reviewText) ?></textarea>
                    </div>
                    <button class="btn btn-primary feedback-submit" type="submit">Share my review <span aria-hidden="true">→</span></button>
                </form>
            </div></div>
        </section>

        <section class="col-lg-7" aria-label="Published reviews">
            <div class="feedback-list-heading">
                <div><span class="storefront-eyebrow">FROM OUR COMMUNITY</span><h2>Kind words &amp; honest thoughts</h2></div>
                <span class="feedback-published-label"><span aria-hidden="true">●</span> Published reviews</span>
            </div>
            <div class="row g-3 mb-4 feedback-rating-summaries">
                <?php foreach ($categoryLabels as $category => $label): ?>
                    <?php $summary = $ratingsSummary[$category] ?? null; ?>
                    <div class="col-sm-6"><div class="card card-body feedback-rating-card">
                        <span class="feedback-category-icon" aria-hidden="true"><?= $category === 'restaurant' ? '✳' : '✦' ?></span>
                        <strong><?= h($label) ?></strong>
                        <?php if ($summary): ?>
                            <span class="feedback-average"><?= number_format((float) $summary['average_rating'], 1) ?><span> / 5</span></span>
                            <span class="feedback-rating-stars" aria-label="<?= number_format((float) $summary['average_rating'], 1) ?> out of 5 stars">★★★★★</span>
                            <span class="feedback-review-count"><?= (int) $summary['review_count'] ?> <?= (int) $summary['review_count'] === 1 ? 'review' : 'reviews' ?></span>
                        <?php else: ?>
                            <span class="feedback-no-rating">Be the first to share</span>
                        <?php endif; ?>
                    </div></div>
                <?php endforeach; ?>
            </div>
            <?php if (!$reviews): ?>
                <div class="feedback-empty"><span aria-hidden="true">✦</span><h3>No reviews just yet</h3><p>Your kind words could be the first.</p></div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($reviews as $review): ?>
                        <article class="card feedback-review-card"><div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <span class="feedback-review-category"><?= h($categoryLabels[$review['category']]) ?></span>
                                    <div class="feedback-review-stars" aria-label="<?= (int) $review['rating'] ?> out of 5 stars"><?= str_repeat('★', (int) $review['rating']) ?><span><?= str_repeat('★', 5 - (int) $review['rating']) ?></span></div>
                                    <p class="feedback-review-meta"><?= h($review['reviewer_name'] ?: 'Anonymous') ?> <span>·</span> <?= h($review['created_at']) ?></p>
                                </div>
                                <?php if ($isAdmin): ?>
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="moderate-feedback">
                                        <input type="hidden" name="feedback_id" value="<?= (int) $review['id'] ?>">
                                        <input type="hidden" name="is_visible" value="<?= (int) $review['is_visible'] === 1 ? '0' : '1' ?>">
                                        <button class="btn btn-sm <?= (int) $review['is_visible'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit">
                                            <?= (int) $review['is_visible'] === 1 ? 'Hide review' : 'Publish review' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if ((int) $review['is_visible'] !== 1): ?><span class="badge text-bg-secondary mt-2">Hidden from public</span><?php endif; ?>
                            <?php if ($review['review_text']): ?><p class="feedback-review-text"><?= nl2br(h($review['review_text'])) ?></p><?php endif; ?>
                        </div></article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
