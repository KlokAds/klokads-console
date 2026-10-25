// MH-Bot — my own app: the console (personal side first, business one tap away) inside, plus
// what only a phone can do: read bank SMS and wallet-app notifications so money is booked by
// itself, show every credit, debit and reminder as a phone notification, and let me see and
// control all of it here (Phone: messages, senders, settings).

import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:http/http.dart' as http;
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:share_plus/share_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'lock.dart';
import 'phone.dart';

const site = 'https://console.klokads.com';
const ink = Color(0xFF0B1020);
const teal = Color(0xFF0D9488);
const nativeChannel = MethodChannel('klok/native');

int appBuild = 0;

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  appBuild = int.tryParse((await PackageInfo.fromPlatform()).buildNumber) ?? 0;
  runApp(const KlokApp());
}

class KlokApp extends StatelessWidget {
  const KlokApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'MH-Bot',
      debugShowCheckedModeBanner: false,
      // The app follows the phone: light or dark.
      themeMode: ThemeMode.system,
      theme: ThemeData(colorSchemeSeed: teal, useMaterial3: true, scaffoldBackgroundColor: const Color(0xFFF6F7FB)),
      darkTheme: ThemeData(colorSchemeSeed: teal, useMaterial3: true, brightness: Brightness.dark, scaffoldBackgroundColor: const Color(0xFF0A0D18)),
      home: const LockGate(child: SitePage()),
    );
  }
}

// ---------------------------------------------------------------------------
// The site inside. Once I'm signed in and the personal side is unlocked, the app pairs
// itself: it gets the private URLs for forwarding, the notification feed and the senders.
// ---------------------------------------------------------------------------

class SitePage extends StatefulWidget {
  const SitePage({super.key});

  @override
  State<SitePage> createState() => SitePageState();
}

class SitePageState extends State<SitePage> with WidgetsBindingObserver {
  InAppWebViewController? web;
  PullToRefreshController? refresh;
  double progress = 0;
  bool failed = false;
  bool pairing = false;
  DateTime? checkedUpdate;
  // The page's own background: the phone's bars above and below take it (dark page, dark bars).
  Color pageColor = ink;

  void setBars(List<dynamic> args) {
    final dark = args.isNotEmpty && args[0] == 'dark';
    var color = dark ? ink : Colors.white;
    final match = RegExp(r'rgba?\((\d+),\s*(\d+),\s*(\d+)').firstMatch(args.length > 1 ? '${args[1]}' : '');
    if (match != null) {
      color = Color.fromARGB(255, int.parse(match[1]!), int.parse(match[2]!), int.parse(match[3]!));
    }
    final icons = dark ? Brightness.light : Brightness.dark;
    SystemChrome.setSystemUIOverlayStyle(SystemUiOverlayStyle(
      statusBarColor: color,
      statusBarIconBrightness: icons,
      systemNavigationBarColor: color,
      systemNavigationBarIconBrightness: icons,
    ));
    if (mounted && color != pageColor) {
      setState(() => pageColor = color);
    }
  }

  @override
  void initState() {
    super.initState();
    refresh = PullToRefreshController(settings: PullToRefreshSettings(color: teal), onRefresh: () async => web?.reload());
    nativeChannel.setMethodCallHandler((call) async {
      if (call.method == 'open' && call.arguments is String) {
        open(call.arguments as String);
      }
    });
    unawaited(Rules.sync());
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// The phone switched light/dark: the page follows (when its theme is "system").
  @override
  void didChangePlatformBrightness() => tellTheme();

  void tellTheme() {
    final dark = WidgetsBinding.instance.platformDispatcher.platformBrightness == Brightness.dark;
    web?.evaluateJavascript(source: 'window.klokSystemTheme && window.klokSystemTheme($dark)');
  }

  /// My theme choice from the app's settings: system, light or dark.
  Future<String> theme() async => '${await web?.evaluateJavascript(source: "window.klokTheme ? window.klokTheme() : 'system'") ?? 'system'}';

  Future<void> setTheme(String value) async => web?.evaluateJavascript(source: "window.klokSetTheme && window.klokSetTheme('$value')");

  /// A notification's page: in front of everything (the app's own screens close), right away —
  /// or as soon as the page view exists, if it doesn't yet.
  String? waiting;
  void open(String url) {
    if (url.isEmpty || url == 'null') {
      return;
    }
    final target = url.startsWith('http') ? url : '$site${url.startsWith('/') ? '' : '/'}$url';
    if (mounted) {
      Navigator.of(context).popUntil((route) => route.isFirst);
    }
    if (web == null) {
      waiting = target;
      return;
    }
    web!.loadUrl(urlRequest: URLRequest(url: WebUri(target)));
  }

  /// Downloads the file (an invoice PDF on its public link) and opens the share sheet with it and the text.
  Future<bool> shareFile(dynamic args) async {
    if (args is! Map || args['url'] is! String) {
      return false;
    }
    try {
      final res = await http.get(Uri.parse(args['url'] as String));
      if (res.statusCode != 200) {
        return false;
      }
      final name = ((args['name'] as String?) ?? 'invoice.pdf').replaceAll(RegExp(r'[^\w.\-]'), '_');
      final file = File('${(await getTemporaryDirectory()).path}/$name');
      await file.writeAsBytes(res.bodyBytes);
      final result = await SharePlus.instance.share(ShareParams(files: [XFile(file.path, mimeType: 'application/pdf')], text: args['text'] as String?));
      return result.status != ShareResultStatus.unavailable;
    } catch (_) {
      return false;
    }
  }

  /// A JSON request as me (the page's own cookies and CSRF token).
  Future<({int status, dynamic body})?> request(String method, String path) async {
    final result = await web?.callAsyncJavaScript(
      functionBody: '''
        const m = document.cookie.match(/(?:^|;\\s*)XSRF-TOKEN=([^;]+)/);
        const r = await fetch(path, {
          method,
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' },
        });
        let data = null;
        try { data = await r.json(); } catch (e) {}
        return { status: r.status, body: data };
      ''',
      arguments: {'path': path, 'method': method},
    );
    final value = result?.value;
    return value is Map ? (status: (value['status'] as num?)?.toInt() ?? 0, body: value['body']) : null;
  }

  /// The site asks for its web PIN, but this app has its own lock (fingerprint / app PIN): with
  /// that lock on, the app opens the personal side itself. With the app lock off, the web PIN stays.
  Future<bool> appUnlock() async {
    if (await Lock.mode() == 'off') {
      return false;
    }
    final config = (await SharedPreferences.getInstance()).getString('config.url');
    if (config == null) {
      return false;
    }
    final token = Uri.parse(config).pathSegments.last;
    final result = await request('POST', '/app/unlock/$token');
    final body = result?.body;
    if (result?.status != 200 || body is! Map) {
      return false;
    }
    open('${body['to'] ?? '/personal'}');
    return true;
  }

  /// On the unlocked personal side, once: the URLs, then permissions, then (if I want) the lock.
  Future<void> pair(WebUri? url) async {
    final prefs = await SharedPreferences.getInstance();
    final paired = prefs.getString('feed.url') != null && prefs.getString('config.url') != null && prefs.getString('followups.url') != null && prefs.getString('inbox.error') == null;
    if (paired || pairing || url == null || url.host != Uri.parse(site).host) {
      return;
    }
    if (!url.path.startsWith('/personal') || url.path.startsWith('/personal/unlock')) {
      return;
    }
    pairing = true;
    try {
      final result = await request('POST', '/personal/app/pair');
      final body = result?.body;
      if (result?.status != 200 || body is! Map) {
        return;
      }
      final first = prefs.getString('feed.url') == null;
      await prefs.setString('inbox.url', body['inbox'] as String);
      await prefs.setString('feed.url', body['feed'] as String);
      await prefs.setString('config.url', body['config'] as String);
      await prefs.setString('followups.url', body['followups'] as String);
      if (first) {
        await prefs.setInt('feed.last', (body['last_id'] as num?)?.toInt() ?? 0);
      }
      await prefs.remove('inbox.error');
      await Rules.sync();
      await nativeChannel.invokeMethod('paired');
      if (first && mounted) {
        await askLock();
        await Permission.notification.request();
        await Permission.sms.request();
        if (mounted) {
          await openPhone(tab: 3, firstTime: true);
        }
      }
    } finally {
      pairing = false;
    }
  }

  /// Asked once, after the first sign-in: how should the app open?
  Future<void> askLock() async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(24, 0, 24, 6),
              child: Text('MH-Bot কীভাবে খুলবে?', style: TextStyle(fontSize: 19, fontWeight: FontWeight.w700)),
            ),
            const Padding(
              padding: EdgeInsets.fromLTRB(24, 0, 24, 12),
              child: Text('পরে Phone › Settings › Security থেকে বদলাতে পারবেন।', style: TextStyle(color: Colors.grey)),
            ),
            ListTile(leading: const Icon(Icons.fingerprint), title: const Text('আঙুলের ছাপ'), subtitle: const Text('Phone-এর lock পিছনে থাকে'), onTap: () => Navigator.pop(context, 'bio')),
            ListTile(leading: const Icon(Icons.pin_outlined), title: const Text('App-এর নিজের PIN'), subtitle: const Text('৬ সংখ্যা'), onTap: () => Navigator.pop(context, 'pin')),
            ListTile(leading: const Icon(Icons.lock_open), title: const Text('এখন না'), subtitle: const Text('Lock বন্ধ থাকবে'), onTap: () => Navigator.pop(context, 'off')),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (choice == 'bio' && await Lock.finger('Lock চালু করতে')) {
      await Lock.setMode('bio');
    } else if (choice == 'pin' && mounted && await newPin(context)) {
      await Lock.setMode('pin');
    }
  }

  /// Is there a newer APK? It is downloaded and installed by the app itself.
  Future<void> checkUpdate({bool now = false}) async {
    if (!now && checkedUpdate != null && DateTime.now().difference(checkedUpdate!) < const Duration(hours: 6)) {
      return;
    }
    final result = await request('GET', '/app/latest');
    final body = result?.body;
    if (body is! Map) {
      return;
    }
    checkedUpdate = DateTime.now();
    final version = (body['version'] as num?)?.toInt() ?? 0;
    final url = body['url'] as String?;
    if (version <= appBuild || url == null || !mounted) {
      if (now && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('এটাই সবচেয়ে নতুন version।')));
      }
      return;
    }
    final allowed = await nativeChannel.invokeMethod<bool>('updateAllowed') ?? false;
    if (!allowed) {
      if (!mounted) {
        return;
      }
      final go = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('নতুন version এসেছে'),
          content: Text('Version ${body['name'] ?? version}। একবার অনুমতি দিন — এরপর app নিজেই নতুনটা বসাবে।'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('পরে')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('অনুমতি দিন')),
          ],
        ),
      );
      if (go == true) {
        await nativeChannel.invokeMethod('askUpdateAllowed');
        checkedUpdate = null;
      }
      return;
    }
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Version ${body['name'] ?? version} নামানো হচ্ছে…')));
    }
    await nativeChannel.invokeMethod('install', {'url': url});
  }

  Future<void> openPhone({int tab = 0, bool firstTime = false}) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PhonePage(site: this, tab: tab, firstTime: firstTime)));
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) {
          return;
        }
        if (await web?.canGoBack() ?? false) {
          await web?.goBack();
        } else {
          await SystemNavigator.pop();
        }
      },
      child: Scaffold(
        backgroundColor: pageColor,
        body: SafeArea(
          child: Stack(
            children: [
              InAppWebView(
                initialUrlRequest: URLRequest(url: WebUri('$site/personal')),
                pullToRefreshController: refresh,
                initialSettings: InAppWebViewSettings(
                  applicationNameForUserAgent: 'KlokApp/$appBuild',
                  javaScriptEnabled: true,
                  domStorageEnabled: true,
                  databaseEnabled: true,
                  thirdPartyCookiesEnabled: false,
                  allowFileAccess: false,
                  useShouldOverrideUrlLoading: true,
                  useOnDownloadStart: true,
                  supportZoom: false,
                ),
                onWebViewCreated: (controller) async {
                  web = controller;
                  // The site's "Phone app" button opens the native screens.
                  controller.addJavaScriptHandler(handlerName: 'appSettings', callback: (_) => openPhone());
                  controller.addJavaScriptHandler(handlerName: 'theme', callback: setBars);
                  // An invoice PDF with its message: the phone's share sheet (WhatsApp, Gmail…), the file attached.
                  controller.addJavaScriptHandler(handlerName: 'shareFile', callback: (args) => shareFile(args.isNotEmpty ? args.first : null));
                  // Opened by tapping a notification: go straight to its page.
                  final first = await nativeChannel.invokeMethod<String>('launchUrl') ?? waiting;
                  waiting = null;
                  if (first != null && first.isNotEmpty) {
                    open(first);
                  }
                },
                shouldOverrideUrlLoading: (controller, action) async {
                  final uri = action.request.url;
                  if (uri == null) {
                    return NavigationActionPolicy.ALLOW;
                  }
                  // Phone calls, WhatsApp and other sites open in their own apps.
                  if (!['http', 'https'].contains(uri.scheme) || uri.host != Uri.parse(site).host) {
                    await launchUrl(uri, mode: LaunchMode.externalApplication);
                    return NavigationActionPolicy.CANCEL;
                  }
                  return NavigationActionPolicy.ALLOW;
                },
                onDownloadStartRequest: (controller, request) async {
                  await launchUrl(request.url, mode: LaunchMode.externalApplication);
                },
                onProgressChanged: (controller, value) {
                  setState(() => progress = value / 100);
                  if (value == 100) {
                    refresh?.endRefreshing();
                  }
                },
                onLoadStop: (controller, url) async {
                  refresh?.endRefreshing();
                  if (failed) {
                    setState(() => failed = false);
                  }
                  tellTheme();
                  // Pages without the site's theme code (sign-in) still colour the bars.
                  final look = await controller.evaluateJavascript(
                      source: "[document.documentElement.classList.contains('dark') ? 'dark' : 'light', getComputedStyle(document.body).backgroundColor]");
                  if (look is List) {
                    setBars(look);
                  }
                  if (url != null && url.host == Uri.parse(site).host && url.path.startsWith('/personal/unlock') && await appUnlock()) {
                    return;
                  }
                  await pair(url);
                  unawaited(checkUpdate());
                  unawaited(nativeChannel.invokeMethod('poll').catchError((_) => null));
                },
                onReceivedError: (controller, request, error) {
                  refresh?.endRefreshing();
                  if (request.isForMainFrame ?? true) {
                    setState(() => failed = true);
                  }
                },
              ),
              if (progress < 1) LinearProgressIndicator(value: progress, minHeight: 2, color: teal, backgroundColor: Colors.transparent),
              if (failed)
                Positioned.fill(
                  child: ColoredBox(
                    color: pageColor,
                    child: Center(
                      child: Padding(
                        padding: const EdgeInsets.all(28),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.wifi_off_rounded, size: 56, color: teal),
                            const SizedBox(height: 14),
                            const Text('নেট নেই', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
                            const SizedBox(height: 8),
                            const Text('Bank SMS phone-এ জমা থাকবে, নেট এলে নিজে যাবে। Phone-এ সব দেখা যাবে।', textAlign: TextAlign.center),
                            const SizedBox(height: 18),
                            FilledButton.icon(
                              style: FilledButton.styleFrom(backgroundColor: teal),
                              onPressed: () {
                                setState(() => failed = false);
                                web?.reload();
                              },
                              icon: const Icon(Icons.refresh),
                              label: const Text('আবার চেষ্টা'),
                            ),
                            TextButton(onPressed: () => openPhone(), child: const Text('Phone: messages দেখুন')),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
