<?php

namespace Kizami;

use Kirby\Http\Response;

/**
 * The /k/snapshot answer, streamed from an open file handle instead of a body
 * string. A database copy can be larger than PHP's memory_limit; with the
 * body in a string such a request died with a fatal error.
 *
 * Kirby hands a Response object through unchanged up to `echo
 * $kirby->render()`, which calls send() — so the override below is what
 * runs in production. The file is already unlinked by then (see
 * SnapshotService::respond()); the handle keeps its content readable.
 */
final class SnapshotResponse extends Response
{
	/** Bytes per write to the output. */
	private const CHUNK = 65536;

	/** @var resource|null */
	private $handle;

	/**
	 * @param resource $handle readable handle of the snapshot copy
	 * @param $path where the copy was; removed after sending in case
	 *             the early unlink did not work
	 * @param array<string,string> $headers
	 */
	public function __construct($handle, private string $path, array $headers)
	{
		parent::__construct('', 'application/vnd.sqlite3', 200, $headers);
		$this->handle = $handle;
	}

	public function __destruct()
	{
		$this->release();
	}

	/**
	 * The whole copy as a string. For tests and debugging only — it loads
	 * everything into memory, which is exactly what production avoids by
	 * calling send(). The handle is rewound, so send() still works after it.
	 */
	public function body(): string
	{
		if (!is_resource($this->handle)) {
			return '';
		}
		rewind($this->handle);
		$content = (string)stream_get_contents($this->handle);
		rewind($this->handle);
		return $content;
	}

	/** Closes the handle and removes the file if it is still there (no-op once gone). */
	private function release(): void
	{
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}
		$this->handle = null;
		if (is_file($this->path)) {
			@unlink($this->path);
		}
	}

	/**
	 * Same three header steps as Response::send(), then the file goes to the
	 * output in chunks. Returns '' because the body is already out; the
	 * caller's `echo` adds nothing.
	 */
	public function send(): string
	{
		http_response_code($this->code());
		foreach ($this->headers() as $key => $value) {
			header($key . ': ' . $value);
		}
		header('Content-Type: ' . $this->type() . '; charset=' . $this->charset());

		if (is_resource($this->handle)) {
			rewind($this->handle);
			// Not fpassthru(): on a plain file it maps the whole file and
			// writes it in ONE call, and an output buffer (output_buffering,
			// zlib.output_compression) then grows to the full size before it
			// flushes. Small writes let the buffer flush every few KB.
			while (!feof($this->handle) && ($chunk = fread($this->handle, self::CHUNK)) !== false) {
				echo $chunk;
			}
		}
		$this->release();
		return '';
	}
}
