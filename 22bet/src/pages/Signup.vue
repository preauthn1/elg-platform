<template>
  <div>
    <Navbar />
    <Title />
    <form class="mx-3" @submit.prevent="">
      <Input
        class="my-4"
        title="Username"
        placeholder="Username*"
        v-model="username"
      />
      <Input
        class="mb-4"
        title="Mobile"
        placeholder="Mobile*"
        v-model="mobile"
        :prefix="`+${countryCode}`"
      />
      <Input
        class="mb-4"
        title="Email"
        placeholder="Email*"
        type="email"
        v-model="email"
      />
      <Input
        class="mb-4"
        title="Password"
        placeholder="Password*"
        type="password"
        v-model="password"
      />
      <div class="form-check mt-3 policy">
        <input
          class="form-check-input"
          type="checkbox"
          v-model="policy"
          id="policy"
        />
        <label for="policy" class="c-registration__rules">
          By clicking this button you confirm that you have read and agree to
          the Terms and Conditions and Privacy Policy of the company and confirm
          that you are of legal age (18+).
        </label>
      </div>

      <button
        @click="submit"
        class="signup-btn my-4"
        type="submit"
        :disabled="invalidInput || loading"
      >
        Register
        <span
          class="spinner-border spinner-border-sm"
          role="status"
          aria-hidden="true"
          v-if="loading"
        ></span>
      </button>
    </form>
    <img class="content-img" src="../assets/content.jpg" />
  </div>
</template>

<script>
import validator from "validator";
import Navbar from "../components/Navbar.vue";
import Title from "@/components/Title.vue";
import Input from "@/components/Input.vue";

export default {
  components: { Navbar, Title, Input },
  computed: {
    countryCode() {
      switch (location.host) {
        case "example.invalid":
          return "63";
        case "example.invalid":
          return "60";
        default:
          return "63";
      }
    },
    invalidInput() {
      return (
        validator.isEmpty(this.username) ||
        validator.isEmpty(this.mobile) ||
        !RegExp("^(0)?[0-9]{9,10}$").test(this.mobile) ||
        validator.isEmpty(this.email) ||
        validator.isEmpty(this.password) ||
        !validator.isEmail(this.email) ||
        !this.policy
      );
    },
  },
  data() {
    return {
      username: "",
      mobile: "",
      email: "",
      password: "",
      policy: false,
      loading: false,
    };
  },
  methods: {
    async submit() {
      this.loading = true;
      const url = new URL("https://example.invalid/api/register");
      const body = JSON.stringify({
        username: this.username,
        mobile: this.countryCode + this.mobile,
        email: this.email,
        password: [REDACTED],
        domain: location.host,
      });
      try {
        const response = await fetch(url, { method: "POST", body });
        if (!response.ok) {
          const responseData = await response.json();
          throw responseData;
        }
        alert("Login message and reward have been sent to your E-mail");
        window.location.reload();
      } catch (error) {
        let message = "";
        for (var property in error) {
          message += error[property] + "\n";
        }
        alert(message);
      }
      this.loading = false;
    },
  },
};
</script>

<style lang="scss" scoped>
.signup-btn {
  width: 100%;
  border: none;
  background: #2eb256;
  color: #fff;
  border-radius: 30px;
  height: 44.08px;
  text-transform: uppercase;
  &:disabled {
    opacity: 0.5;
  }
}

.content-img {
  pointer-events: none;
  width: 100%;
}

.policy {
  color: #234e55;
}
</style>
