<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class VueController extends Controller
{
    public function index()
    {
        $indexFile = public_path('dist/index.html');

        if (!file_exists($indexFile) || !is_readable($indexFile)) {
            abort(404, 'Vue app not built. Run npm run build first.');
        }

        $content = file_get_contents($indexFile);

        if ($content === false) {
            abort(500, 'Unable to read Vue app index file.');
        }

        return response($content)->header('Content-Type', 'text/html');
    }
}