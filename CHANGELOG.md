## v0.1.1 - 2025-12-29
- Fix: allow handlers to use \$payload (alias of $data) to avoid DI BindingResolutionException
- Feat: add WsContext helpers: isEstablished(), disconnect(), close(), disconnectAndForget()
