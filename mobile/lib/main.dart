// Klok — my own app: the console (personal side first, business one tap away) inside, plus
// what only a phone can do: read bank SMS and wallet-app notifications so money is booked by
// itself, show every credit, debit and reminder as a phone notification, and let me see and
// control all of it here (Phone: messages, senders, settings).

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:local_auth/local_auth.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

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
      title: 'Klok',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: teal, useMaterial3: true, scaffoldBackgroundColor: const Color(0xFFF6F7FB)),
      home: const LockGate(child: SitePage()),
    );
  }
}

// ---------------------------------------------------------------------------
// The lock. Off until I turn it on: after the first sign-in the app asks whether to use my
// fingerprint. Once on, it asks every time the app opens and after a minute away.
// ---------------------------------------------------------------------------

class Lock {
  static Future<bool> on() async => (await SharedPreferences.getInstance()).getBool('lock.on') ?? false;

  static Future<void> set(bool value) async => (await SharedPreferences.getInstance()).setBool('lock.on', value);

  /// My fingerprint, or the phone's own PIN/pattern behind it. A phone with no screen lock passes.
  static Future<bool> check(String reason) async {
    final auth = LocalAuthentication();
    try {
      if (!await auth.isDeviceSupported()) {
        return true;
      }
      return await auth.authenticate(localizedReason: reason, options: const AuthenticationOptions(stickyAuth: true));
    } on PlatformException catch (e) {
      return e.code == 'NotAvailable' || e.code == 'PasscodeNotSet' || e.code == 'NotEnrolled';
    }
  }
}

class LockGate extends StatefulWidget {
  const LockGate({super.key, required this.child});

  final Widget child;

  @override
  State<LockGate> createState() => _LockGateState();
}

class _LockGateState extends State<LockGate> with WidgetsBindingObserver {
  bool locked = false;
  bool asking = false;
  DateTime? leftAt;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    Lock.on().then((on) {
      if (on) {
        setState(() => locked = true);
        unlock();
      }
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) async {
    // The fingerprint prompt itself sends the app to the background for a moment.
    if (asking) {
      return;
    }
    if (state == AppLifecycleState.paused) {
      leftAt = DateTime.now();
    } else if (state == AppLifecycleState.resumed && leftAt != null) {
      final away = DateTime.now().difference(leftAt!);
      leftAt = null;
      if (away >= const Duration(minutes: 1) && await Lock.on()) {
        setState(() => locked = true);
        unlock();
      }
      unawaited(nativeChannel.invokeMethod('poll').catchError((_) => null));
    }
  }

  Future<void> unlock() async {
    if (asking) {
      return;
    }
    asking = true;
    final ok = await Lock.check('Klok খুলতে');
    asking = false;
    if (mounted) {
      setState(() => locked = !ok);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        widget.child,
        if (locked)
          Positioned.fill(
            child: Material(
              color: ink,
              child: SafeArea(
                child: Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.fingerprint, size: 76, color: Color(0xFF5EEAD4)),
                      const SizedBox(height: 18),
                      const Text('Klok', style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: Colors.white)),
                      const SizedBox(height: 28),
                      FilledButton.icon(
                        style: FilledButton.styleFrom(backgroundColor: teal, padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 14)),
                        onPressed: unlock,
                        icon: const Icon(Icons.lock_open),
                        label: const Text('খুলুন'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
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

class SitePageState extends State<SitePage> {
  InAppWebViewController? web;
  PullToRefreshController? refresh;
  double progress = 0;
  bool failed = false;
  bool pairing = false;
  DateTime? checkedUpdate;

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
  }

  void open(String url) {
    final target = url.startsWith('http') ? url : '$site${url.startsWith('/') ? '' : '/'}$url';
    web?.loadUrl(urlRequest: URLRequest(url: WebUri(target)));
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

  /// On the unlocked personal side, once: the URLs, then permissions, then (if I want) the lock.
  Future<void> pair(WebUri? url) async {
    final prefs = await SharedPreferences.getInstance();
    final paired = prefs.getString('feed.url') != null && prefs.getString('config.url') != null && prefs.getString('inbox.error') == null;
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
          await openPhone(tab: 2, firstTime: true);
        }
      }
    } finally {
      pairing = false;
    }
  }

  /// Asked once, after the first sign-in: should Klok open with my fingerprint?
  Future<void> askLock() async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        icon: const Icon(Icons.fingerprint, size: 40, color: teal),
        title: const Text('আঙুলের ছাপ দিয়ে খুলবেন?'),
        content: const Text('চালু করলে Klok খুলতে প্রতিবার আপনার আঙুলের ছাপ (বা phone-এর lock) লাগবে। পরে Phone › Settings থেকে বদলানো যায়।'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('এখন না')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('চালু করুন')),
        ],
      ),
    );
    if (yes == true && await Lock.check('Klok-এর lock চালু করতে')) {
      await Lock.set(true);
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
        backgroundColor: Colors.white,
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
                  // Opened by tapping a notification: go straight to its page.
                  final first = await nativeChannel.invokeMethod<String>('launchUrl');
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
                    color: Colors.white,
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
