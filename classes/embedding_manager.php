<?php
/**
 * Embedding Manager — RAG pipeline step 2
 *
 * Handles:
 *  - Generating text embeddings via OpenAI text-embedding-3-small API
 *  - Storing / updating chunks + embeddings in block_pulso_content_chunks
 *  - Cosine-similarity search (PHP, works with any SQL backend)
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

class embedding_manager {

    /** OpenAI embeddings endpoint. */
    const EMBEDDINGS_URL = 'https://api.openai.com/v1/embeddings';

    /** Embedding model — 1536 dimensions, cheapest OpenAI option. */
    const EMBEDDING_MODEL = 'text-embedding-3-small';

    /** How many chunks to embed per batch (OpenAI allows up to 2048 inputs). */
    const BATCH_SIZE = 20;

    /** Minimum cosine similarity to include a chunk as context. */
    const MIN_SIMILARITY = 0.30;

    private string $apikey;

    public function __construct() {
        $config = get_config('block_pulso');
        if (empty($config->openai_key)) {
            throw new \moodle_exception('error_no_apikey', 'block_pulso');
        }
        $this->apikey = $config->openai_key;
    }

    // ----------------------------------------------------------------
    // Indexing: store chunks and generate embeddings
    // ----------------------------------------------------------------

    /**
     * Index (or re-index) all chunks for a course.
     *
     * Skips chunks whose content_hash hasn't changed.
     * Deletes chunks from deleted modules.
     *
     * @param int   $courseid
     * @param array $chunks   Output of content_extractor::extract_course_content()
     * @return array ['indexed'=>int, 'skipped'=>int, 'deleted'=>int]
     */
    public function index_course_chunks(int $courseid, array $chunks): array {
        global $DB;

        $stats = ['indexed' => 0, 'skipped' => 0, 'deleted' => 0, 'embedded' => 0, 'embed_errors' => 0];
        $now   = time();

        // Build a set of current cmids so we can delete stale records.
        $current_cmids = array_unique(array_column($chunks, 'cmid'));

        // Delete chunks for cmids no longer in the course.
        if (!empty($current_cmids)) {
            // Use NOT IN / <> safely through Moodle helper (equal=false).
            [$not_in_sql, $in_params] = $DB->get_in_or_equal($current_cmids, SQL_PARAMS_NAMED, 'cmid', false);
            $stale = $DB->get_records_select(
                'block_pulso_content_chunks',
                "courseid = :courseid AND cmid $not_in_sql",
                array_merge(['courseid' => $courseid], $in_params),
                '',
                'id'
            );
            if ($stale) {
                $DB->delete_records_list('block_pulso_content_chunks', 'id', array_keys($stale));
                $stats['deleted'] = count($stale);
            }
        }

        // Separate new chunks from unchanged ones and collect batches for embedding.
        $to_embed   = []; // chunks that need a new embedding
        $to_upsert  = []; // all chunks that need DB upsert

        foreach ($chunks as $chunk) {
            $hash = hash('sha256', $chunk['chunk_text']);

            // Check existing record. El courseid es OBLIGATORIO en el filtro:
            // sin él, un chunk sintético de un curso (cmid negativo, ver
            // content_extractor::extract_course_structure) podía resolverse
            // contra la fila de OTRO curso y sobrescribirla al hacer el update.
            $existing = $DB->get_record('block_pulso_content_chunks', [
                'courseid'    => $courseid,
                'cmid'        => $chunk['cmid'],
                'chunk_index' => $chunk['chunk_index'],
            ]);

            if ($existing && $existing->content_hash === $hash) {
                $stats['skipped']++;
                continue;
            }

            $record = (object)[
                'courseid'       => $courseid,
                'cmid'           => $chunk['cmid'],
                'module_type'    => $chunk['module_type'],
                'module_name'    => $chunk['module_name'],
                'chunk_index'    => $chunk['chunk_index'],
                'chunk_text'     => $chunk['chunk_text'],
                'content_hash'   => $hash,
                'embedding_json' => null,
                'token_count'    => $chunk['token_count'],
                'timecreated'    => $existing ? $existing->timecreated : $now,
                'timemodified'   => $now,
            ];

            if ($existing) {
                $record->id = $existing->id;
            }

            $to_upsert[] = $record;
            $to_embed[]  = ['record' => $record, 'text' => $chunk['chunk_text']];
        }

        if (empty($to_embed)) {
            return $stats;
        }

        // Generate embeddings in batches.
        for ($i = 0; $i < count($to_embed); $i += self::BATCH_SIZE) {
            $batch    = array_slice($to_embed, $i, self::BATCH_SIZE);
            $texts    = array_column($batch, 'text');
            $vectors  = [];

            try {
                $vectors = $this->generate_embeddings($texts);
            } catch (\Throwable $e) {
                // Keep indexing text chunks even if embeddings endpoint fails.
                // Retrieval can fallback to lexical mode.
                $stats['embed_errors']++;
                error_log('Pulso RAG embeddings batch failed: ' . $e->getMessage());
            }

            foreach ($batch as $j => $item) {
                $record = $item['record'];
                $record->embedding_json = isset($vectors[$j])
                    ? json_encode($vectors[$j])
                    : null;

                if ($record->embedding_json !== null) {
                    $stats['embedded']++;
                }

                if (isset($record->id)) {
                    $DB->update_record('block_pulso_content_chunks', $record);
                } else {
                    $DB->insert_record('block_pulso_content_chunks', $record);
                }
                $stats['indexed']++;
            }
        }

        return $stats;
    }

    // ----------------------------------------------------------------
    // Retrieval: embed query and find nearest chunks
    // ----------------------------------------------------------------

    /**
     * Find the top-K most relevant chunks for a free-text query.
     *
     * @param int    $courseid
     * @param string $query     User's natural language question.
     * @param int    $top_k     Number of chunks to return (default 5).
     * @return array  Array of chunk records ordered by similarity DESC.
     */
    public function find_relevant_chunks(int $courseid, string $query, int $top_k = 5): array {
        global $DB;

        // Get all indexed chunks for this course that have an embedding.
        $all_chunks = $DB->get_records_select(
            'block_pulso_content_chunks',
            'courseid = :courseid AND embedding_json IS NOT NULL',
            ['courseid' => $courseid],
            '',
            'id, module_type, module_name, chunk_index, chunk_text, embedding_json'
        );

        if (empty($all_chunks)) {
            // Fallback: lexical retrieval over raw chunk text.
            return $this->find_relevant_chunks_lexical($courseid, $query, $top_k);
        }

        // Embed the query.
        try {
            $query_vectors = $this->generate_embeddings([$query]);
        } catch (\Throwable $e) {
            error_log('Pulso RAG query embedding failed, fallback to lexical retrieval: ' . $e->getMessage());
            return $this->find_relevant_chunks_lexical($courseid, $query, $top_k);
        }

        if (empty($query_vectors) || empty($query_vectors[0])) {
            return $this->find_relevant_chunks_lexical($courseid, $query, $top_k);
        }
        $query_vector = $query_vectors[0];

        // Score each chunk.
        $scored = [];
        foreach ($all_chunks as $chunk) {
            $chunk_vector = json_decode($chunk->embedding_json, true);
            if (!is_array($chunk_vector)) {
                continue;
            }
            $sim = $this->cosine_similarity($query_vector, $chunk_vector);
            if ($sim >= self::MIN_SIMILARITY) {
                $scored[] = [
                    'record'     => $chunk,
                    'similarity' => $sim,
                ];
            }
        }

        // Sort by similarity descending.
        usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        // Return top-K chunk records.
        return array_map(
            fn($s) => $s['record'],
            array_slice($scored, 0, $top_k)
        );
    }

    /**
     * Lexical fallback retrieval when embeddings are unavailable.
     *
     * @param int $courseid
     * @param string $query
     * @param int $top_k
     * @return array
     */
    private function find_relevant_chunks_lexical(int $courseid, string $query, int $top_k = 5): array {
        global $DB;

        $chunks = $DB->get_records_select(
            'block_pulso_content_chunks',
            'courseid = :courseid',
            ['courseid' => $courseid],
            '',
            'id, module_type, module_name, chunk_index, chunk_text'
        );

        if (empty($chunks)) {
            return [];
        }

        $terms = $this->tokenize_query($query);
        if (empty($terms)) {
            return array_slice(array_values($chunks), 0, $top_k);
        }

        $scored = [];
        foreach ($chunks as $chunk) {
            $score = $this->lexical_score($chunk->chunk_text, $terms);
            if ($score > 0) {
                $scored[] = ['record' => $chunk, 'score' => $score];
            }
        }

        if (empty($scored)) {
            return array_slice(array_values($chunks), 0, min($top_k, count($chunks)));
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_map(fn($s) => $s['record'], array_slice($scored, 0, $top_k));
    }

    /**
     * Tokenize query into normalized terms.
     *
     * @param string $query
     * @return array
     */
    private function tokenize_query(string $query): array {
        $q = mb_strtolower($query, 'UTF-8');
        $q = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q);
        $parts = preg_split('/\s+/u', trim($q));
        $parts = array_filter($parts, function($t) {
            return mb_strlen($t, 'UTF-8') >= 3;
        });
        return array_values(array_unique($parts));
    }

    /**
     * Simple term-frequency score.
     *
     * @param string $text
     * @param array $terms
     * @return int
     */
    private function lexical_score(string $text, array $terms): int {
        $t = mb_strtolower($text, 'UTF-8');
        $score = 0;
        foreach ($terms as $term) {
            $score += substr_count($t, $term);
        }
        return $score;
    }

    // ----------------------------------------------------------------
    // OpenAI Embeddings API
    // ----------------------------------------------------------------

    /**
     * Generate embeddings for an array of text strings.
     *
     * @param  string[] $texts
     * @return float[][]  Array of embedding vectors (one per input text).
     * @throws \moodle_exception on API error.
     */
    public function generate_embeddings(array $texts): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (empty($texts)) {
            return [];
        }

        $payload = json_encode([
            'model' => self::EMBEDDING_MODEL,
            'input' => $texts,
        ]);

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ]);

        $raw = $curl->post(self::EMBEDDINGS_URL, $payload);

        if ($curl->errno) {
            throw new \moodle_exception('error_api_connection', 'block_pulso', '', $curl->error);
        }

        $response = json_decode($raw, true);

        if (isset($response['error'])) {
            throw new \moodle_exception(
                'error_api_response',
                'block_pulso',
                '',
                $response['error']['message'] ?? 'Unknown embedding error'
            );
        }

        if (empty($response['data'])) {
            return [];
        }

        // Re-order by index (OpenAI guarantees order but be safe).
        $vectors = [];
        foreach ($response['data'] as $item) {
            $vectors[$item['index']] = $item['embedding'];
        }
        ksort($vectors);

        return array_values($vectors);
    }

    // ----------------------------------------------------------------
    // Math helpers
    // ----------------------------------------------------------------

    /**
     * Cosine similarity between two equal-length float vectors.
     *
     * @param  float[] $a
     * @param  float[] $b
     * @return float  Value in [-1, 1]; 1 = identical direction.
     */
    public function cosine_similarity(array $a, array $b): float {
        $dot   = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len   = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dot   += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
