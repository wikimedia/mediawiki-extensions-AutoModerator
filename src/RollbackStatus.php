<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

class RollbackStatus {
	private int $reverted;
	private string $status;
	private bool $shouldRevert;

	public function __construct( int $reverted, string $status, bool $shouldRevert = false ) {
		$this->reverted = $reverted;
		$this->status = $status;
		$this->shouldRevert = $shouldRevert;
	}

	public function isReverted(): bool {
		return $this->reverted === 1;
	}

	public function getReverted(): int {
		return $this->reverted;
	}

	public function shouldRevert(): bool {
		return $this->shouldRevert;
	}

	public function getStatus(): string {
		return $this->status;
	}
}
