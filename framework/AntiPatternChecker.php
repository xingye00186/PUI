<?php

/**
 * AntiPatternChecker - 框架反模式检查工具
 *
 * 检查项:
 *   - hwnd_in_app: Application 不应持有 hWnd 属性
 *   - hwnd_in_renderer: BaseRenderer 不应持有 hWnd 属性
 *   - hdc_in_renderer: BaseRenderer 不应持有 hdc 属性
 *   - hwnd_not_in_render_ctx: GdiRenderContext 必须持有 hWnd
 *   - hdc_not_in_render_ctx: GdiRenderContext 必须持有 hdc
 *   - hdc_param_in_methods: 绘制方法不应接收 hdc 参数
 *   - direct_cpp_call: 业务代码（除 GdiRenderContext）不应直接调用 vue_* 函数
 *
 * 使用方式: php framework/AntiPatternChecker.php [path]
 */
class AntiPatternChecker
{
    /** 检查规则定义 */
    private array $rules = [
        'hwnd_in_app' => [
            'severity' => 'ERROR',
            'pattern' => '/class\s+Application\b[\s\S]*?\{[\s\S]*?private\s+int\s+\$hWnd/s',
            'message' => 'Application 不应持有 hWnd 属性，应由 GdiRenderContext 持有',
        ],
        'hwnd_in_renderer' => [
            'severity' => 'ERROR',
            'pattern' => '/class\s+BaseRenderer\b[\s\S]*?\{[\s\S]*?private\s+int\s+\$hWnd/s',
            'message' => 'BaseRenderer 不应持有 hWnd 属性，应由 GdiRenderContext 持有',
        ],
        'hdc_in_renderer' => [
            'severity' => 'ERROR',
            'pattern' => '/class\s+BaseRenderer\b[\s\S]*?\{[\s\S]*?private\s+int\s+\$hdc/s',
            'message' => 'BaseRenderer 不应持有 hdc 属性，应由 GdiRenderContext 持有',
        ],
        'hwnd_not_in_render_ctx' => [
            'severity' => 'ERROR',
            'pattern' => '/class\s+GdiRenderContext\b[\s\S]*?\{(?![\s\S]*?private\s+int\s+\$hWnd)/',
            'message' => 'GdiRenderContext 必须持有 $hWnd 属性',
        ],
        'hdc_not_in_render_ctx' => [
            'severity' => 'ERROR',
            'pattern' => '/class\s+GdiRenderContext\b[\s\S]*?\{(?![\s\S]*?private\s+int\s+\$hdc)/',
            'message' => 'GdiRenderContext 必须持有 $hdc 属性',
        ],
        'hdc_param_in_methods' => [
            'severity' => 'WARN',
            'pattern' => '/(fillRect|drawText|drawButton)\s*\(\s*[^)]*?\$hdc[^)]*?\)/',
            'message' => '绘制方法不应接收 hdc 参数，应由 GdiRenderContext 内部管理',
        ],
        'direct_cpp_call' => [
            'severity' => 'ERROR',
            'pattern' => '/vue_(window_create|window_show|begin_paint|end_paint|fill_rect|draw_text|draw_button)/',
            'message' => '业务代码不应直接调用 vue_* C++ 函数，应通过 GdiRenderContext',
        ],
    ];

    /** 排除的文件（允许直接调用 C++ 函数） */
    private array $excludedFiles = [
        'GdiRenderContext.php',
        'vue_calc.cc',
    ];

    /** 检查结果 */
    private array $errors = [];
    private array $warnings = [];

    /**
     * 检查目录或文件
     */
    public function check(string $path): void
    {
        if (is_dir($path)) {
            $this->checkDirectory($path);
        } else {
            $this->checkFile($path);
        }
    }

    /**
     * 检查目录
     */
    private function checkDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->checkFile($file->getPathname());
            }
        }
    }

    /**
     * 检查单个文件
     */
    private function checkFile(string $filepath): void
    {
        $filename = basename($filepath);

        // 跳过排除的文件
        if (in_array($filename, $this->excludedFiles)) {
            return;
        }

        $content = file_get_contents($filepath);
        if ($content === false) {
            return;
        }

        foreach ($this->rules as $ruleId => $rule) {
            // 对于 direct_cpp_call 规则，排除 GdiRenderContext
            if ($ruleId === 'direct_cpp_call' && $filename === 'GdiRenderContext.php') {
                continue;
            }

            if (preg_match($rule['pattern'], $content)) {
                if ($rule['severity'] === 'ERROR') {
                    $this->errors[] = [
                        'file' => $filepath,
                        'rule' => $ruleId,
                        'message' => $rule['message'],
                    ];
                } else {
                    $this->warnings[] = [
                        'file' => $filepath,
                        'rule' => $ruleId,
                        'message' => $rule['message'],
                    ];
                }
            }
        }
    }

    /**
     * 输出检查结果
     */
    public function report(): void
    {
        echo "========================================\n";
        echo "  AntiPatternChecker - 框架反模式检查\n";
        echo "========================================\n\n";

        if (empty($this->errors) && empty($this->warnings)) {
            echo "[OK] 未检测到反模式\n";
            return;
        }

        if (!empty($this->errors)) {
            echo "ERRORS (" . count($this->errors) . "):\n";
            echo str_repeat('-', 50) . "\n";
            foreach ($this->errors as $err) {
                echo "  [ERROR] {$err['file']}\n";
                echo "          Rule: {$err['rule']}\n";
                echo "          {$err['message']}\n\n";
            }
        }

        if (!empty($this->warnings)) {
            echo "WARNINGS (" . count($this->warnings) . "):\n";
            echo str_repeat('-', 50) . "\n";
            foreach ($this->warnings as $warn) {
                echo "  [WARN] {$warn['file']}\n";
                echo "         Rule: {$warn['rule']}\n";
                echo "         {$warn['message']}\n\n";
            }
        }
    }

    /**
     * 返回是否有错误
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}

// CLI 入口
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $path = $argv[1] ?? __DIR__;
    $checker = new AntiPatternChecker();
    $checker->check($path);
    $checker->report();
    exit($checker->hasErrors() ? 1 : 0);
}