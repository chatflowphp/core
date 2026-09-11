<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

/**
 * Another worker changed the conversation while this event was being handled.
 *
 * The runtime catches it and runs the tick again on top of the state that won, so handlers may
 * run more than once for one inbound event. Nothing is delivered before the snapshot is stored,
 * so the user never sees the discarded attempt.
 */
class ConversationConflictException extends StorageException {}
