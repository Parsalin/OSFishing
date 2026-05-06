<?php
/**
 * FAQ.php - FAQ entries and player questions
 */

class FAQ
{
    // ── Public: get active FAQs ──
    public static function getAll(): array {
        return db()->query('
            SELECT id, question, answer, category
            FROM faq_entries
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ')->fetchAll();
    }

    // ── Player: submit a question ──
    public static function submitQuestion(?int $playerId, ?string $playerName, string $question): array {
        $question = trim($question);
        if (strlen($question) < 5) json_error('Question is too short');
        if (strlen($question) > 2000) json_error('Question is too long (max 2000 chars)');

        $stmt = db()->prepare('
            INSERT INTO player_questions (player_id, player_name, question)
            VALUES (:pid, :pn, :q)
        ');
        $stmt->execute([
            ':pid' => $playerId,
            ':pn'  => $playerName,
            ':q'   => $question,
        ]);
        return ['message' => 'Your question has been submitted! Check back for an answer.'];
    }

    // ── Admin: list all questions ──
    public static function listQuestions(string $status = 'all'): array {
        $where = '';
        $params = [];
        if ($status !== 'all') {
            $where = 'WHERE pq.status = :s';
            $params = [':s' => $status];
        }
        $stmt = db()->prepare("
            SELECT pq.*, p.display_name
            FROM player_questions pq
            LEFT JOIN players p ON p.id = pq.player_id
            $where
            ORDER BY pq.created_at DESC
        ");
        $stmt->execute($params);
        return ['questions' => $stmt->fetchAll()];
    }

    // ── Admin: reply to a question ──
    public static function replyQuestion(int $questionId, string $reply, string $status = 'answered'): array {
        $stmt = db()->prepare('
            UPDATE player_questions
            SET admin_reply = :r, status = :s, replied_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([':r' => $reply, ':s' => $status, ':id' => $questionId]);
        return ['message' => 'Reply saved'];
    }

    // ── Admin: dismiss a question ──
    public static function dismissQuestion(int $questionId): array {
        $stmt = db()->prepare('UPDATE player_questions SET status = :s WHERE id = :id');
        $stmt->execute([':s' => 'dismissed', ':id' => $questionId]);
        return ['message' => 'Question dismissed'];
    }

    // ── Admin: list all FAQ entries (including inactive) ──
    public static function listAllFAQ(): array {
        return ['faqs' => db()->query('
            SELECT * FROM faq_entries ORDER BY sort_order ASC, id ASC
        ')->fetchAll()];
    }

    // ── Admin: create FAQ ──
    public static function createFAQ(string $question, string $answer, string $category = 'general'): array {
        $stmt = db()->prepare('
            INSERT INTO faq_entries (question, answer, category)
            VALUES (:q, :a, :c)
        ');
        $stmt->execute([':q' => $question, ':a' => $answer, ':c' => $category]);
        return ['message' => 'FAQ created', 'id' => (int)db()->lastInsertId()];
    }

    // ── Admin: update FAQ ──
    public static function updateFAQ(int $id, array $data): array {
        $allowed = ['question', 'answer', 'category', 'sort_order', 'is_active'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        if (empty($sets)) json_error('Nothing to update');
        $stmt = db()->prepare('UPDATE faq_entries SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        return ['message' => 'FAQ updated'];
    }

    // ── Admin: delete FAQ ──
    public static function deleteFAQ(int $id): array {
        $stmt = db()->prepare('DELETE FROM faq_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return ['message' => 'FAQ deleted'];
    }
}
