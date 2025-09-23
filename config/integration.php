<?php

return [
    // Habilita o processamento em chunks para integrações grandes
    'enable_chunking' => true,

    // Número de anúncios por chunk (confirmado: 200)
    'chunk_size' => 200,

    // Paralelismo máximo por integração (confirmado: 3)
    'max_parallel_chunks_per_integration' => 3,

    // Retenção de execuções de integração (dias) (confirmado: 30)
    'run_retention_days' => 30,
];


