package com.boopapp.app;

import android.annotation.SuppressLint;
import android.content.Intent;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.view.KeyEvent;
import android.view.View;
import android.webkit.*;
import android.widget.ProgressBar;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

public class MainActivity extends AppCompatActivity {

    // ⚠️ 修改为你的实际网站地址
    private static final String APP_URL = "https://your-domain.com/app.html";
    // ⚠️ 同步修改为你的域名（用于判断站内/站外链接）
    private static final String APP_HOST = "your-domain.com";

    private WebView webView;
    private SwipeRefreshLayout swipeRefresh;
    private ProgressBar progressBar;
    private long lastBackPressTime = 0;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        swipeRefresh = findViewById(R.id.swipeRefresh);
        webView      = findViewById(R.id.webview);
        progressBar  = findViewById(R.id.progressBar);

        // ── WebSettings ──────────────────────────────
        WebSettings ws = webView.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        ws.setDatabaseEnabled(true);
        ws.setCacheMode(WebSettings.LOAD_DEFAULT);
        ws.setMediaPlaybackRequiresUserGesture(false);
        ws.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        ws.setBuiltInZoomControls(false);
        ws.setDisplayZoomControls(false);
        ws.setSupportZoom(false);
        ws.setLoadWithOverviewMode(true);
        ws.setUseWideViewPort(true);
        // 标记为 APP 内嵌请求
        ws.setUserAgentString(ws.getUserAgentString() + " BoopApp/1.0 Android");

        // ── WebViewClient ────────────────────────────
        webView.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                swipeRefresh.setRefreshing(false);
                progressBar.setVisibility(View.VISIBLE);
                progressBar.setProgress(0);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                progressBar.setVisibility(View.GONE);
                swipeRefresh.setRefreshing(false);
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest req) {
                String url = req.getUrl().toString();
                if (url.startsWith("mailto:") || url.startsWith("tel:")) {
                    startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                    return true;
                }
                // 站外链接用系统浏览器打开
                if (!url.contains(APP_HOST)) {
                    startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                    return true;
                }
                return false;
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest req,
                                        WebResourceError err) {
                // 只处理主框架加载失败
                if (req.isForMainFrame()) {
                    view.loadUrl("file:///android_asset/error.html");
                }
            }
        });

        // ── WebChromeClient ──────────────────────────
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                progressBar.setProgress(newProgress);
                if (newProgress == 100) progressBar.setVisibility(View.GONE);
            }

            @Override
            public boolean onConsoleMessage(ConsoleMessage msg) {
                return true; // 屏蔽控制台日志
            }
        });

        // ── 下拉刷新 ─────────────────────────────────
        swipeRefresh.setOnRefreshListener(() -> webView.reload());
        swipeRefresh.setColorSchemeColors(0xFF4f73f8);

        webView.loadUrl(APP_URL);
    }

    // ── 返回键：优先 WebView 后退 ─────────────────────
    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_BACK) {
            if (webView.canGoBack()) {
                webView.goBack();
                return true;
            }
            long now = System.currentTimeMillis();
            if (now - lastBackPressTime < 2000) {
                finish();
            } else {
                lastBackPressTime = now;
                Toast.makeText(this, "再按一次退出 Boop", Toast.LENGTH_SHORT).show();
            }
            return true;
        }
        return super.onKeyDown(keyCode, event);
    }

    @Override
    protected void onResume() {
        super.onResume();
        webView.onResume();
    }

    @Override
    protected void onPause() {
        super.onPause();
        webView.onPause();
    }

    @Override
    protected void onDestroy() {
        if (webView != null) {
            webView.stopLoading();
            webView.destroy();
        }
        super.onDestroy();
    }
}
