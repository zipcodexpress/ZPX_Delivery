const apiUrl = process.env.EXPO_PUBLIC_API_URL || '';
const production = process.env.APP_ENV === 'production';
if (production && !apiUrl.startsWith('https://')) throw new Error('Production mobile builds require an HTTPS EXPO_PUBLIC_API_URL.');
const localDevelopment = !production && (!apiUrl || apiUrl.startsWith('http://'));

export default {
  expo: {
    name: 'ZipcodeXpress Delivery',
    slug: 'zpx-delivery',
    scheme: 'zpxdelivery',
    version: '0.1.0',
    platforms: ['ios', 'android'],
    orientation: 'portrait',
    userInterfaceStyle: 'automatic',
    ios: {
      bundleIdentifier: 'com.zipcodexpress.delivery',
      infoPlist: {
        NSAppTransportSecurity: { NSAllowsLocalNetworking: localDevelopment },
      },
    },
    android: {
      package: 'com.zipcodexpress.delivery',
    },
    plugins: ['expo-secure-store', ['expo-camera', { cameraPermission: 'Allow ZipcodeXpress to scan the locker terminal pairing QR code.', microphonePermission: false, recordAudioAndroid: false, barcodeScannerEnabled: true }]],
  },
};
