<?php

return [
    'disk' => 'intelligence-private',
    // These links select a notebook only. No credentials or Drive access tokens here.
    'notebook_url' => env('MODEL_TRAINING_NOTEBOOK_URL', 'https://colab.research.google.com/github/getibplay-cmyk/pfe/blob/main/notebooks/model_retraining_workbench.ipynb'),
];
