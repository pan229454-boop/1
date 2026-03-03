-keep class com.boopapp.app.** { *; }
-keepattributes JavascriptInterface
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}
-dontwarn okhttp3.**
-dontwarn okio.**
