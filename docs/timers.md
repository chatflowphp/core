# Timers

A conversation sometimes has to act without the user: remind them tomorrow, follow up after a
day of silence, close a deal that was not confirmed in time. A timer wakes the conversation at a
point in time with a system tick.

```php
$ctx->wakeAt(86400, 'silence', ['last' => $ctx->getText()]);   // seconds from now
$ctx->wakeAt(new DateInterval('P1D'), 'silence');               // an interval from now
$ctx->wakeAt(new DateTimeImmutable('tomorrow 09:00'), 'digest');
$ctx->cancelTimer('silence');
```

Timers need a store; pass one to the application:

```php
$application = new Application($adapter, $container, timers: new FileTimerStore(__DIR__ . '/storage'));
```

## Ids

The default id is `<conversation>:<reason>`. Scheduling the same reason again **moves** the timer,
so "wake me a day after the last message" is one `wakeAt()` per message. Pass your own id to keep
several timers with one reason.

## Delivery

A scheduler calls `runDue()` as often as the shortest timer you use:

```php
$ran = $application->runDue();          // now, by the application clock
$ran = $application->runDue($at, 50);   // explicit time and batch size
```

Each due timer becomes one system tick (`reason: timer:<reason>`) in its conversation:

- the active scene, if it implements `TimerListenerInterface`:

  ```php
  public function onTimer(Context $ctx, Timer $timer): void
  {
      $ctx->reply('Still there?');
      $ctx->leave();
  }
  ```

- otherwise the application listener for the reason:

  ```php
  $application->onTimer('digest', static function (Context $ctx, Timer $timer): void { ... });
  ```

- otherwise the timer is dropped and a `timer.dropped` runtime event is recorded.

The timer is cancelled after its tick ran. A crash between the two runs it again on the next
`runDue()`, so a listener should tolerate a repeated wake-up.

## Why A Side Effect

`wakeAt()` and `cancelTimer()` do not touch the store inside the tick. They schedule a
[side effect](side-effects.md) that writes the store after the snapshot is committed: a
rolled-back tick arms nothing, and a crash after the commit still arms the timer.

## Event Time

`$ctx->getOccurredAt()` is when the event happened on the platform, taken from the platform
payload by the adapter; system events carry the time they were created. Timers count from the
application clock, not from the event, so a delayed webhook does not shorten the wait.

## Drivers

| Driver | Use |
| --- | --- |
| `MemoryTimerStore` | tests and single-process bots |
| `FileTimerStore` | small single-host bots; one JSON file per timer, finding due timers scans the directory |
| `RedisTimerStore` | production; a sorted set scored by time plus a hash of timer bodies |
| `DatabaseTimerStore` | production with MySQL, PostgreSQL or SQLite through PDO |

```php
$pdo->exec(DatabaseTimerStore::createTableSql('mysql'));   // 'pgsql', 'sqlite'
```

The table (`chatflow_timers` by default) has `timer_id`, `conversation_id`, `due_at` and
`timer_data`, with indexes on `due_at` and `conversation_id`. The PostgreSQL and SQLite DDL
contains several statements separated by `;`.

## Runtime Events

`timer.ran`, `timer.dropped`.
