class OID {
  final String _value;

  const OID._internal(this._value);

  static final Set<OID> values = [
    OID.etsiQcsCompliance,
    OID.etsiQcsRetentionPeriod,
    OID.etsiQcsQcSSCD,
    OID.dsa,
    OID.ecPublicKey,
    OID.prime256v1,
    OID.ecdsaWithSHA256,
    OID.ecdsaWithSHA512,
    OID.rsaEncryption,
    OID.md2WithRSAEncryption,
    OID.md4WithRSAEncryption,
    OID.md5WithRSAEncryption,
    OID.sha1WithRSAEncryption,
    OID.RSAES_OAEP,
    OID.mgf1,
    OID.pSpecified,
    OID.RSASSA_PSS,
    OID.sha256WithRSAEncryption,
    OID.sha384WithRSAEncryption,
    OID.sha512WithRSAEncryption,
    OID.pkcs7data,
    OID.pkcs7signedData,
    OID.pkcs7envelopedData,
    OID.emailAddress,
    OID.signingCertificateV2,
    OID.contentType,
    OID.messageDigest,
    OID.signingTime,
    OID.dsaWithSha1,
    OID.certificateExtension,
    OID.jurisdictionOfIncorporationSP,
    OID.jurisdictionOfIncorporationC,
    OID.authorityInfoAccess,
    OID.qcStatements,
    OID.cps,
    OID.unotice,
    OID.serverAuth,
    OID.clientAuth,
    OID.ocsp,
    OID.caIssuers,
    OID.dateOfBirth,
    OID.desCBC,
    OID.sha1,
    OID.sha256,
    OID.sha384,
    OID.sha512,
    OID.md5,
    OID.VeriSignEVpolicy,
    OID.extendedValidation,
    OID.organizationValidated,
    OID.subjectKeyIdentifier,
    OID.keyUsage,
    OID.subjectAltName,
    OID.issuerAltName,
    OID.basicConstraints,
    OID.cRLDistributionPoints,
    OID.certificatePolicies,
    OID.authorityKeyIdentifier,
    OID.extKeyUsage,
    OID.subjectDirectoryAttributes,
    OID.organizationName,
    OID.organizationalUnitName,
    OID.businessCategory,
    OID.postalCode,
    OID.commonName,
    OID.surname,
    OID.givenName,
    OID.dnQualifier,
    OID.serialNumber,
    OID.countryName,
    OID.localityName,
    OID.stateOrProvinceName,
    OID.streetAddress,
    OID.desEDE3CBC,
    OID.aes128CBC,
    OID.aes192CBC,
    OID.aes256CBC,
    OID.nsCertType,
    OID.nsComment,
    OID.privateKeyUsagePeriod,
    OID.cRLNumber,
    OID.cRLReason,
    OID.expirationDate,
    OID.instructionCode,
    OID.invalidityDate,
    OID.deltaCRLIndicator,
    OID.issuingDistributionPoint,
    OID.certificateIssuer,
    OID.nameConstraints,
    OID.policyMappings,
    OID.policyConstraints,
    OID.freshestCRL,
    OID.inhibitAnyPolicy,
    OID.codeSigning,
    OID.emailProtection,
    OID.timeStamping,
    OID.pkcsSha256,
    OID.sha2Family,
    OID.sha3_244,
    OID.sha3_256,
    OID.sha3_384,
  ].toSet();

  static OID? fromValue(String? value) {
    if (value != null) {
      try {
        return OID.values.firstWhere((element) => element.toValue() == value);
      } catch (e) {
        return null;
      }
    }
    return null;
  }

  String toValue() => _value;

  String name() =>
      _oidMapName.containsKey(this._value) ? _oidMapName[this._value]! : "";

  @override
  String toString() => "($_value, ${name()})";

  static const etsiQcsCompliance = const OID._internal("0.4.0.1862.1.1");
  static const etsiQcsRetentionPeriod = const OID._internal("0.4.0.1862.1.3");
  static const etsiQcsQcSSCD = const OID._internal("0.4.0.1862.1.4");
  static const dsa = const OID._internal("1.2.840.10040.4.1");
  static const ecPublicKey = const OID._internal("1.2.840.10045.2.1");
  static const prime256v1 = const OID._internal("1.2.840.10045.3.1.7");
  static const ecdsaWithSHA256 = const OID._internal("1.2.840.10045.4.3.2");
  static const ecdsaWithSHA512 = const OID._internal("1.2.840.10045.4.3.4");
  static const rsaEncryption = const OID._internal("1.2.840.113549.1.1.1");
  static const md2WithRSAEncryption =
      const OID._internal("1.2.840.113549.1.1.2");
  static const md4WithRSAEncryption =
      const OID._internal("1.2.840.113549.1.1.3");
  static const md5WithRSAEncryption =
      const OID._internal("1.2.840.113549.1.1.4");
  static const sha1WithRSAEncryption =
      const OID._internal("1.2.840.113549.1.1.5");
  static const RSAES_OAEP = const OID._internal("1.2.840.113549.1.1.7");
  static const mgf1 = const OID._internal(".2.840.113549.1.1.8");
  static const pSpecified = const OID._internal(".2.840.113549.1.1.9");
  static const RSASSA_PSS = const OID._internal(".2.840.113549.1.1.10");
  static const sha256WithRSAEncryption =
      const OID._internal("1.2.840.113549.1.1.11");
  static const sha384WithRSAEncryption =
      const OID._internal("1.2.840.113549.1.1.12");
  static const sha512WithRSAEncryption =
      const OID._internal("1.2.840.113549.1.1.13");
  static const pkcs7data = const OID._internal("1.2.840.113549.1.7.1");
  static const pkcs7signedData = const OID._internal("1.2.840.113549.1.7.2");
  static const pkcs7envelopedData = const OID._internal("1.2.840.113549.1.7.3");
  static const emailAddress = const OID._internal("1.2.840.113549.1.9.1");
  static const signingCertificateV2 =
      const OID._internal("1.2.840.113549.0.0.0.0.47");
  static const contentType = const OID._internal("1.2.840.113549.1.9.3");
  static const messageDigest = const OID._internal("1.2.840.113549.1.9.4");
  static const signingTime = const OID._internal("1.2.840.113549.1.9.5");
  static const dsaWithSha1 = const OID._internal("1.2.840.10040.4.3");
  static const certificateExtension =
      const OID._internal("0.0.0.0.4.1.11129.2.4.2");
  static const jurisdictionOfIncorporationSP =
      const OID._internal("0.0.0.0.0.0.0.0.2.1.2");
  static const jurisdictionOfIncorporationC =
      const OID._internal("0.0.0.0.0.0.0.0.2.1.3");
  static const authorityInfoAccess = const OID._internal("0.0.0.0.0.0.0.0.1");
  static const qcStatements = const OID._internal("0.0.0.0.0.0.0.0.3");
  static const cps = const OID._internal("0.0.0.0.0.0.0.0.1");
  static const unotice = const OID._internal("0.0.0.0.0.0.0.0.2");
  static const serverAuth = const OID._internal("0.0.0.0.0.0.0.0.1");
  static const clientAuth = const OID._internal("0.0.0.0.0.0.0.0.2");
  static const ocsp = const OID._internal("0.0.0.0.0.0.0.0.1");
  static const caIssuers = const OID._internal("0.0.0.0.0.0.0.0.2");
  static const dateOfBirth = const OID._internal("0.0.0.0.0.0.0.0.1");
  static const desCBC = const OID._internal("0.0.0.0.2.7");
  static const sha1 = const OID._internal("0.0.0.0.2.26");
  static const pkcsSha256 = const OID._internal("0.0.0.0.4.1.22554.1.2.1");
  static const sha2Family = const OID._internal("0.0.0.0.4.1.22554.1.2");
  static const sha3_244 = const OID._internal("0.0.0.0.0.0.0.0.7");
  static const sha3_256 = const OID._internal("0.0.0.0.0.0.0.0.8");
  static const sha3_384 = const OID._internal("0.0.0.0.0.0.0.0.9");
  static const md5 = const OID._internal("0.0.0.0.0.0.0.0");
  static const sha256 = const OID._internal("0.0.0.0.0.0.0.0.1");
  static const sha384 = const OID._internal("0.0.0.0.0.0.0.0.2");
  static const sha512 = const OID._internal("0.0.0.0.0.0.0.0.3");
  static const VeriSignEVpolicy =
      const OID._internal("0.0.0.0.113733.0.0.0.0");
  static const extendedValidation = const OID._internal("0.0.0.0.1");
  static const organizationValidated = const OID._internal("0.0.0.0.2.2");
  static const subjectKeyIdentifier = const OID._internal("0.0.0.0");
  static const keyUsage = const OID._internal("0.0.0.0");
  static const subjectAltName = const OID._internal("0.0.0.0");
  static const issuerAltName = const OID._internal("0.0.0.0");
  static const basicConstraints = const OID._internal("0.0.0.0");
  static const cRLDistributionPoints = const OID._internal("0.0.0.0");
  static const certificatePolicies = const OID._internal("0.0.0.0");
  static const authorityKeyIdentifier = const OID._internal("0.0.0.0");
  static const extKeyUsage = const OID._internal("0.0.0.0");
  static const subjectDirectoryAttributes = const OID._internal("0.0.0.0");
  static const organizationName = const OID._internal("0.0.0.0");
  static const organizationalUnitName = const OID._internal("0.0.0.0");
  static const businessCategory = const OID._internal("0.0.0.0");
  static const postalCode = const OID._internal("0.0.0.0");
  static const commonName = const OID._internal("0.0.0.0");
  static const surname = const OID._internal("0.0.0.0");
  static const givenName = const OID._internal("0.0.0.0");
  static const dnQualifier = const OID._internal("0.0.0.0");
  static const serialNumber = const OID._internal("0.0.0.0");
  static const countryName = const OID._internal("0.0.0.0");
  static const localityName = const OID._internal("0.0.0.0");
  static const stateOrProvinceName = const OID._internal("0.0.0.0");
  static const streetAddress = const OID._internal("0.0.0.0");
  static const desEDE3CBC = const OID._internal("1.2.840.113549.3.7");
  static const aes128CBC = const OID._internal("0.0.0.0.0.0.0.0.2");
  static const aes192CBC = const OID._internal("0.0.0.0.0.0.0.0.22");
  static const aes256CBC = const OID._internal("0.0.0.0.0.0.0.0.42");
  static const nsCertType = const OID._internal("0.0.0.0.113730.1.1");
  static const nsComment = const OID._internal("0.0.0.0.113730.1.13");
  static const privateKeyUsagePeriod = const OID._internal("0.0.0.0");
  static const cRLNumber = const OID._internal("0.0.0.0");
  static const cRLReason = const OID._internal("0.0.0.0");
  static const expirationDate = const OID._internal("0.0.0.0");
  static const instructionCode = const OID._internal("0.0.0.0");
  static const invalidityDate = const OID._internal("0.0.0.0");
  static const deltaCRLIndicator = const OID._internal("0.0.0.0");
  static const issuingDistributionPoint = const OID._internal("0.0.0.0");
  static const certificateIssuer = const OID._internal("0.0.0.0");
  static const nameConstraints = const OID._internal("0.0.0.0");
  static const policyMappings = const OID._internal("0.0.0.0");
  static const policyConstraints = const OID._internal("0.0.0.0");
  static const freshestCRL = const OID._internal("0.0.0.0");
  static const inhibitAnyPolicy = const OID._internal("0.0.0.0");
  static const codeSigning = const OID._internal("0.0.0.0.0.0.0.0.3");
  static const emailProtection = const OID._internal("0.0.0.0.0.0.0.0.4");
  static const timeStamping = const OID._internal("0.0.0.0.0.0.0.0.8");

  bool operator ==(value) => value == _value;

  @override
  int get hashCode => _value.hashCode;

  static const Map<String, String> _oidMapName = {
    "0.4.0.1862.1.1": "etsiQcsCompliance",
    "0.4.0.1862.1.3": "etsiQcsRetentionPeriod",
    "0.4.0.1862.1.4": "etsiQcsQcSSCD",
    "1.2.840.10040.4.1": "dsa",
    "1.2.840.10045.2.1": "ecPublicKey",
    "1.2.840.10045.3.1.7": "prime256v1",
    "1.2.840.10045.4.3.2": "ecdsaWithSHA256",
    "1.2.840.10045.4.3.4": "ecdsaWithSHA512",
    "1.2.840.113549.1.1.1": "rsaEncryption",
    "1.2.840.113549.1.1.2": "md2WithRSAEncryption",
    "1.2.840.113549.1.1.3": "md4WithRSAEncryption",
    "1.2.840.113549.1.1.4": "md5WithRSAEncryption",
    "1.2.840.113549.1.1.5": "sha1WithRSAEncryption",
    "1.2.840.113549.1.1.7": "RSAES-OAEP",
    ".2.840.113549.1.1.8": "mgf1",
    ".2.840.113549.1.1.9": "pSpecified",
    ".2.840.113549.1.1.10": "RSASSA-PSS",
    "1.2.840.113549.1.1.11": "sha256WithRSAEncryption",
    "1.2.840.113549.1.1.12": "sha384WithRSAEncryption",
    "1.2.840.113549.1.1.13": "sha512WithRSAEncryption",
    "1.2.840.113549.1.7.1": "pkcs7data",
    "1.2.840.113549.1.7.2": "pkcs7signedData",
    "1.2.840.113549.1.7.3": "pkcs7envelopedData",
    "1.2.840.113549.1.9.1": "emailAddress",
    "1.2.840.113549.0.0.0.0.47": "signingCertificateV2",
    "1.2.840.113549.1.9.3": "contentType",
    "1.2.840.113549.1.9.4": "messageDigest",
    "1.2.840.113549.1.9.5": "signingTime",
    "1.2.840.10040.4.3": "dsaWithSha1",
    "0.0.0.0.4.1.11129.2.4.2": "certificateExtension",
    "0.0.0.0.0.0.0.0.2.1.2": "jurisdictionOfIncorporationSP",
    "0.0.0.0.0.0.0.0.2.1.3": "jurisdictionOfIncorporationC",
    "0.0.0.0.0.0.0.0.1": "authorityInfoAccess",
    "0.0.0.0.0.0.0.0.3": "qcStatements",
    "0.0.0.0.0.0.0.0.1": "cps",
    "0.0.0.0.0.0.0.0.2": "unotice",
    "0.0.0.0.0.0.0.0.1": "serverAuth",
    "0.0.0.0.0.0.0.0.2": "clientAuth",
    "0.0.0.0.0.0.0.0.1": "ocsp",
    "0.0.0.0.0.0.0.0.2": "caIssuers",
    "0.0.0.0.0.0.0.0.1": "dateOfBirth",
    "0.0.0.0.2.7": "desCBC",
    "0.0.0.0.2.26": "sha1",
    "0.0.0.0.4.1.22554.1.2.1": "pkcsSha256",
    "0.0.0.0.4.1.22554.1.2": "sha2Family",
    "0.0.0.0.0.0.0.0.7": "sha3_244",
    "0.0.0.0.0.0.0.0.8": "sha3_256",
    "0.0.0.0.0.0.0.0.9": "sha3_384",
    "0.0.0.0.0.0.0.0.1": "sha256",
    "0.0.0.0.0.0.0.0.2": "sha384",
    "0.0.0.0.0.0.0.0.3": "sha512",
    "0.0.0.0.0.0.0.0": "md5",
    "0.0.0.0.113733.0.0.0.0": "VeriSign EV policy",
    "0.0.0.0.1": "extendedValidation",
    "0.0.0.0.2.2": "organizationValidated",
    "0.0.0.0": "subjectKeyIdentifier",
    "0.0.0.0": "keyUsage",
    "0.0.0.0": "subjectAltName",
    "0.0.0.0": "issuerAltName",
    "0.0.0.0": "basicConstraints",
    "0.0.0.0": "cRLDistributionPoints",
    "0.0.0.0": "certificatePolicies",
    "0.0.0.0": "authorityKeyIdentifier",
    "0.0.0.0": "extKeyUsage",
    "0.0.0.0": "subjectDirectoryAttributes",
    "0.0.0.0": "organizationName",
    "0.0.0.0": "organizationalUnitName",
    "0.0.0.0": "businessCategory",
    "0.0.0.0": "postalCode",
    "0.0.0.0": "commonName",
    "0.0.0.0": "surname",
    "0.0.0.0": "givenName",
    "0.0.0.0": "dnQualifier",
    "0.0.0.0": "serialNumber",
    "0.0.0.0": "countryName",
    "0.0.0.0": "localityName",
    "0.0.0.0": "stateOrProvinceName",
    "0.0.0.0": "streetAddress",
    "1.2.840.113549.3.7": "des-EDE3-CBC",
    "0.0.0.0.0.0.0.0.2": "aes128-CBC",
    "0.0.0.0.0.0.0.0.22": "aes192-CBC",
    "0.0.0.0.0.0.0.0.42": "aes256-CBC",
    "0.0.0.0.113730.1.1": "nsCertType",
    "0.0.0.0.113730.1.13": "nsComment",
    "0.0.0.0": "privateKeyUsagePeriod",
    "0.0.0.0": "cRLNumber",
    "0.0.0.0": "cRLReason",
    "0.0.0.0": "expirationDate",
    "0.0.0.0": "instructionCode",
    "0.0.0.0": "invalidityDate",
    "0.0.0.0": "deltaCRLIndicator",
    "0.0.0.0": "issuingDistributionPoint",
    "0.0.0.0": "certificateIssuer",
    "0.0.0.0": "nameConstraints",
    "0.0.0.0": "policyMappings",
    "0.0.0.0": "policyConstraints",
    "0.0.0.0": "freshestCRL",
    "0.0.0.0": "inhibitAnyPolicy",
    "0.0.0.0.0.0.0.0.3": "codeSigning",
    "0.0.0.0.0.0.0.0.4": "emailProtection",
    "0.0.0.0.0.0.0.0.8": "timeStamping",
  };
}
